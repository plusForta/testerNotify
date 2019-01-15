<?php


/**
 * Gets all tickets from Planio that are:
 *   * in ready to test status
 *   * are in the entwicklung project
 *   * have changed since $lastRun
 *
 * @param int $lastRun the time (in unixtime) since the last run
 *
 * @return array an array of tickets to process.
 */
function getTickets(int $lastRun)
{
    global $debug;

    $serviceUrl  = getenv('PLANIO_URL') . '/issues.json';
    $timeFilter  = '';
    $queryParams = [
        'status_id' => 14,
//        'project_id' => 134,
    ];
    if ($lastRun) {
        $timeFilter                = '>=' . date('Y-m-d', $lastRun) .
                                     'T' . date('H:i:s', $lastRun) . 'Z';
        $queryParams['updated_on'] = $timeFilter;
        if ($debug) {
            echo "Limiting query to " . $timeFilter . PHP_EOL;
        }
    }
    $headers = [
        'Content-Type'      => 'application/json',
        'X-Redmine-API-Key' => getenv('PLANIO_API_KEY'),
    ];

    $client = new GuzzleHttp\Client();

    try {
        $res        = $client->request(
            'GET',
            $serviceUrl,
            [
                'headers' => $headers,
                'query'   => $queryParams,
            ]
        );
        $ticketList = json_decode($res->getBody());
    } catch (\GuzzleHttp\Exception\GuzzleException $e) {
        die ($e->getMessage());
    }

    return $ticketList;
}

/**
 * Returns the slack id for a given email address
 *
 * @param $email string email address to find in slack
 *
 * @return array
 */
function getSlackUserId(string $email)
{
    $url         = 'https://slack.com/api/users.lookupByEmail';
    $queryParams = [
        'token' => getenv('SLACK_TOKEN'),
        'email' => $email,
    ];

    $client = new GuzzleHttp\Client();

    try {
        $res = $client->request(
            'GET',
            $url,
            [
                'query' => $queryParams,
            ]
        );
    } catch (\GuzzleHttp\Exception\GuzzleException $e) {
        die ($e->getMessage());
    }
    $response = json_decode($res->getBody());

    if ($response->ok) {
        return [
            'id'   => $response->user->id,
            'name' => $response->user->name,
        ];

    }

    return [
        'id'   => '',
        'name' => '',
    ];

}

/**
 * Returns an array of planio users with planio IDs as the key various data
 * as the value
 *
 * @return array user data
 */

function buildUserLookupTable()
{
    $specials = [
        'krause@best-data.de'        => 'U75SU1A9Y',
        'katja.ortz@kautionsfrei.de' => 'U76PMSH52',
    ];

    $data        = [];
    $planIoUsers = getPlanioUsers();
    foreach ($planIoUsers->users as $user) {

        // handle the special cases (where the email doesn't match between
        // plan.io and slack
        if (array_key_exists($user->mail, $specials)) {
            $table[$user->mail] = $specials[$user->mail];
        }

        $slack = getSlackUserId($user->mail);

        $data[$user->id] = [
            'firstname' => $user->firstname,
            'lastname'  => $user->lastname,
            'mail'      => $user->mail,
            'slackId'   => $slack['id'],
            'slackName' => $slack['name'],
        ];
    }

    return $data;
}

/**
 * @param int $lastRun
 *
 * @return mixed
 */
function getPlanIoUsers()
{
    $serviceUrl = getenv('PLANIO_URL') . '/users.json';
    $headers    = [
        'Content-Type'      => 'application/json',
        'X-Redmine-API-Key' => getenv('PLANIO_API_KEY'),
    ];

    $client = new GuzzleHttp\Client();

    try {
        $res      = $client->request(
            'GET',
            $serviceUrl,
            [
                'headers' => $headers,
            ]
        );
        $userList = json_decode($res->getBody());
    } catch (\GuzzleHttp\Exception\GuzzleException $e) {
        die ($e->getMessage());
    }

    return $userList;
}

function getState()
{
    $lastRunFile = getenv('RUNFILE');
    //checkout if the file exists if not create
    if (file_exists($lastRunFile)) { // file exists, pull any processed tickets out of the set from planio
        $state = json_decode(file_get_contents($lastRunFile), true);
    } else {
        $state = [
            'lastRun'  => null,
            'lastMail' => null,
        ];
        file_put_contents($lastRunFile, json_encode($state));
    }

    return $state;
}

/**
 * @param array $timeLimit
 *
 * @return array
 */
function getTicketsToProcess(int $timeLimit, array $lookup): array
{
    global $debug;
    // get the tickets since the last run
    $ticketsToProcess = getTickets($timeLimit);

    $ticketsToNotify = [
        'noTest' => [],
        'assign' => [],
    ];

    // split work into two buckets...
    // noTest (no tester assigned) and
    // assign (tester assigned)

    foreach ($ticketsToProcess->issues as $ticket) {
        $tester = $ticket->custom_fields[1]->value ?? '';
        // if tester is blank ....
        if ($tester === '') {
            if ($debug) {
                echo 'noTester ' . $ticket->id . PHP_EOL;
            }
            $ticketsToNotify['noTest'][] = [
                'assigned_to_name' => $ticket->assigned_to->name,
                'assigned_to_id'   => $ticket->assigned_to->id,
                'assigned_to_data' => $lookup[$ticket->assigned_to->id],
                'id'               => $ticket->id,
                'subject'          => $ticket->subject,
            ];
        } else {
            if ($debug) {
                echo 'testAssign: ' . $ticket->id . PHP_EOL;
            }
            $ticketsToNotify['assign'][] = [
                'tester'           => $tester,
                'tester_data'      => $lookup[intval($tester)],
                'assigned_to_name' => $ticket->assigned_to->name,
                'assigned_to_id'   => $ticket->assigned_to->id,
                'assigned_to_data' => $lookup[$ticket->assigned_to->id],
                'id'               => $ticket->id,
                'subject'          => $ticket->subject,
            ];
        }
        if ($debug) {
            var_dump($ticket);
            echo PHP_EOL;
        }
    }

    // return the array (with two sub arrays)
    return $ticketsToNotify;
}

function sendSlackMessages($work)
{
    global $debug;

    $settings = [
        'username'   => 'TestingNag',
        'link_names' => true,
    ];
    $client   = new Maknz\Slack\Client(getenv('SLACK_WEBHOOK'), $settings);

    foreach ($work['noTest'] as $noTest) {
        $url  = getenv('PLANIO_URL') . '/issues/' . $noTest['id'];
        $msg  = 'Ticket #' . $noTest['id'] . ' ist bereit zu testen, hat aber keinen zugeordneten Tester.';
        $user = '@' . $noTest['assigned_to_data']['slackName'];
        if (!$debug) {
            $client->send($user . ': ' . $msg . ' ' . $url);
        } else {
            echo 'send: ' . $user . ': ' . $msg . ' ' . $url . PHP_EOL;
        }


    }

    foreach ($work['assign'] as $assign) {
        $url  = getenv('PLANIO_URL') . '/issues/' . $assign['id'];
        $msg  = 'Ticket #' . $assign['id'] . ' ist bereit zum Testen und du bist der zugewiesene Tester!';
        $user = '@' . $assign['tester_data']['slackName'];
        if (!$debug) {
            $client->send($user . ': ' . $msg . ' ' . $url);
        } else {
            echo 'send: ' . $user . ': ' . $msg . ' ' . $url . PHP_EOL;
        }
    }

}

function sendEmailMessages($work)
{
    global $debug;

    $client = new \Postmark\PostmarkClient(getenv('PM_SERVER_ID'));

    // make new arrays for each email address.

    $emails = [];

    foreach ($work['noTest'] as $noTest) {
        $emails[$noTest['assigned_to_data']['mail']]['noTest'][] = $noTest;
    }

    foreach ($work['assign'] as $assign) {
        $emails[$assign['tester_data']['mail']]['assign'][] = $assign;
    }


    foreach ($emails as $email) {

        // if there are not tickets for a particular category, make an
        // empty array element.
        if (!array_key_exists('noTest', $email)) {
            $email['noTest'] = [];
        }
        if (!array_key_exists('assign', $email)) {
            $email['assign'] = [];
        }

        $templateData = [
            "subject_name"       => "Daily Testing Nag Email",
            "notest_header"      => '',
            "ticket_list_notest" => [],
            "assign_header"      => "",
            "ticket_list_assign" => [],
            "signature"          => "The Test Notifier",
        ];

        $to = null;

        foreach ($email['noTest'] as $ticket) {
            $to                                   = $ticket['assigned_to_name'] . ' <' .
                                                    $ticket['assigned_to_data']['mail'] . '>';
            $templateData['notest_header']        = 'Tickets that have no tester assigned!';
            $templateData['ticket_list_notest'][] = [
                'url'  => getenv('PLANIO_URL') . '/issues/' . $ticket['id'],
                'name' => '#' . $ticket['id'] . ': ' . $ticket['subject'],
            ];
        }

        foreach ($email['assign'] as $ticket) {
            $to                                   = $ticket['tester_data']['firstname'] . ' ' .
                                                    $ticket['tester_data']['lastname'] . ' <' .
                                                    $ticket['tester_data']['mail'] . '>';
            $templateData['assign_header']        = 'Tickets that are assigned to you for testing';
            $templateData['ticket_list_assign'][] = [
                'url'  => getenv('PLANIO_URL') . '/issues/' . $ticket['id'],
                'name' => '#' . $ticket['id'] . ': ' . $ticket['subject'],
            ];
        }
        if (!$debug) {
            $sendResult = $client->sendEmailWithTemplate(
                "testNotify@plusforta.de",
                $to,
                9276967,
                $templateData
            );
        } else {
            echo "Would have sent an email to ${to}" . PHP_EOL;
            var_dump($templateData);
        }
    }
}

//
//    foreach ($mailCategory as $email => $body) {
//        if (empty($email)) {
//            continue;
//        }
//
//        $mailBody = [
//            "subject_name"     => "Every Ticket you are involved in, which is in 'Ready to Test' state",
//            "body_tester"      => "The Tickets you have to test",
//            "body_author"      => "The Tickets you created and are in the 'Ready to Test' state",
//            "body_assigned_to" => "The Tickets you worked on and put in the 'Ready to Test' state",
//            "commenter_name"   => "commenter_name_Value",
//        ];
//
//
//        foreach ($body as $categoryName => $issueIds) {
//            foreach ($issueIds as $issueId) {
//                $attachmentCategory              = 'attachment_details_' . $categoryName;
//                $mailBody[$attachmentCategory][] = getMailAttachmentById($issueId);
//            }
//        }
//
//        $client->sendEmailWithTemplate(
//            "robot@kautionsfrei.de",
//            $email,
//            9276967,
//            $mailBody
//        );
//    }
//}
//
///**
// * @param $issueId
// *
// * @return array
// */
//function getMailAttachmentById($issueId)
//{
//    return [
//        "attachment_url"  => "https://kautionsfrei.plan.io/issues/" . $issueId,
//        "attachment_name" => "https://kautionsfrei.plan.io/issues/" . $issueId,
//    ];
//}
//
///**
// * @param $issueId
// *
// * @return string
// */
//
//
///**
// * @param $newIdsSinceLastRun
// */
//function processMail($newIdsSinceLastRun)
//{
//
//    $timestamp   = time();
//    $date        = date("Y-m-d H:i:s", $timestamp);
//    $lastRunFile = '/tmp/testerNotify.CheckDateToSendNightlyMails.txt';
//
//    $datetime = explode(" ", $date);
//    $dateNow  = $datetime[0];
//    $timeNow  = $datetime[1];
//
//
//    if (file_exists($lastRunFile)) {
//        $getDateTimeLastRun = file_get_contents($lastRunFile);
//        if (strtotime($dateNow) > strtotime($getDateTimeLastRun)) {
//            sendEmailMessages($newIdsSinceLastRun);
//        }
//    } elseif (strtotime($timeNow) <= "01:00:00") {
//        sendEmailMessages($newIdsSinceLastRun);
//    }
//
//    file_put_contents($lastRunFile, $date);
//}
