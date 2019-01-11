<?php

use Postmark\PostmarkClient;

/**
 * @param      $url
 * @param      $key
 * @param null $filter
 *
 * @return mixed
 */
function getJsonObjFromPlanioURL($url, $key, $filter = null)
{
    $serviceUrl = $url;
    $apiKey = $key;
    $headers = array(
        'Content-Type: application/json',
        'X-Redmine-API-Key: '. $apiKey
    );

    $params = '';
    if (isset($filter)) {
        foreach ($filter as $key => $value) {
            $params .= $key. '=' .$value. '&';
        }
        $params = trim($params, '&');
    }

    $rest = curl_init($serviceUrl);

    curl_setopt_array($rest, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HEADER => 0,
        CURLOPT_URL => $serviceUrl.'.json?'.$params,
        CURLOPT_HTTPHEADER => $headers
    ]);


    //execute the session
    $curlResponse = curl_exec($rest);


    //finish off the session
    curl_close($rest);
    return json_decode($curlResponse, true);
}


/**
 * @param $response
 *
 * @return array
 */
function getPlanioIssuesArrayFromResponse($response)
{
    $issuesArray = [];
    foreach ($response['issues'] as $issue) {
        $issuesArray[] = [
            'ticket'      => $issue['id'],
            'tester'      => $issue['custom_fields'][1]['value'] ?? '',
            'assigned_to' => $issue['assigned_to']['id'] ?? '',
            'author'      => $issue['author']['id']
        ];
    }

    return $issuesArray;
}



//taking the email adress of the user itself, because email adress between slack and planio are matching.
//notice email must be public, check under account settings.
/**
 * @param $issues
 *
 * @return mixed
 */
function replaceUserIdsWithEmails($issues)
{

    //preventing multiple connection for an id we already had
    $knownIds = [];
    foreach ($issues as &$issue) {
        foreach ($issue as $key => $value) {
            if ($key !== 'ticket') {
                $email = $knownIds[$value] ?? null;
                if (!$email && $value) {
                    $response = getJsonObjFromPlanioURL(
                        'https://kautionsfrei.plan.io/users/' . $value,
                        '2d9977f1c2578de68068616410e78a8a05fac126'
                    );
                    $email = $response['user']['mail'];
                    $knownIds[$value] = $email;
                }

                $issue[$key] = $email;
            }
        }
    }

    return $issues;
}


/**
 * @param      $url
 * @param      $token
 * @param null $filter
 *
 * @return mixed
 */
function getSlackUsers($url, $token, $filter = null)
{
    $serviceUrl = $url;
    $headers = array(
        'Content-Type: application/x-www-form-urlencoded'
    );

    $params = '';
    if (isset($filter)) {
        foreach ($filter as $key => $value) {
            $params .= $key. '=' .$value. '&';
        }
        $params = trim($params, '&');
    }

    $rest = curl_init($serviceUrl);

    curl_setopt_array($rest, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HEADER => 0,
        CURLOPT_URL => $serviceUrl.'?token='.$token.$params,
        CURLOPT_HTTPHEADER => $headers
    ]);


    //execute the session
    $curlResponse = curl_exec($rest);


    //finish off the session
    curl_close($rest);
    return json_decode($curlResponse, true);
}


/**
 * @return array|mixed
 */
function getEmailSlackIdMapping()
{
    $slackUsers = getSlackUsers(
        'https://slack.com/api/users.list',
        'xoxp-241218047456-243839032835-494966478247-9f8903c5eaa8d30d686ea6489434c173'
    );

    $emailSlackIdMap = [];

    foreach ($slackUsers['members'] as $userId) {
        if (isset($userId['profile']['email'])) {
            $emailSlackIdMap[$userId['profile']['email']] = $userId['id'];
        }
    }

    return $emailSlackIdMap;
}


/**
 * @param $issuesForNotification
 */
function sendSlackMessages($issuesForNotification)
{
    $EmailSlackIdMap = getEmailSlackIdMapping();

    $client = setupSlack();

    foreach ($issuesForNotification as $issue) {
        $msgToSend = 'https://kautionsfrei.plan.io/issues/' . $issue['ticket'] . ' Ready to Test';

        $slackId = null;
        if (isset($issue['tester'])) {
            $slackId = $EmailSlackIdMap[$issue['tester']] ?? null;
        }

        if (!$slackId) {
            $slackId = $EmailSlackIdMap[$issue['author']] ?? null;
        }

        if ($slackId) {
            $client
                -> to('@'. $slackId)
                -> send($msgToSend . ': <@'. $slackId . '>');
        }
    }
}

/**
 * @return \Maknz\Slack\Client
 */
function setupSlack()
{
    $url = 'https://hooks.slack.com/services/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';
    // Instantiate without defaults

    $settings = [
        'channel'      => '#testernotify',
        'link_names'   => true,
        'unfurl_links' => true
    ];

    $client = new Maknz\Slack\Client($url, $settings);

    return $client;
}


/**
 * @param $array
 *
 * @return array
 */
function getAllCategories($array)
{
    $matchedKeys = array_filter(
        array_keys($array),
        function ($filter) {
            return preg_match('/^(?:(?!ticket).)*$/', $filter);
        }
    );

    return array_values($matchedKeys);
}

/**
 * @param $issues
 *
 * @return array
 */
function getAllInvolvedMails($issues)
{
    $matchedKeys=[];
    foreach ($issues as $issue) {
        unset($issue['ticket']);
        $matchedKeys = array_merge($matchedKeys, array_values($issue));
    }

    return array_filter(array_unique($matchedKeys));
}


const MAPPING = [
    'attachment_details_tester' => 'tester'
];

/**
 * @param $newIdsSinceLastRun
 */
function sendEmailMessages($newIdsSinceLastRun)
{



    $client = new PostmarkClient("4289d43f-4297-4805-bd96-64f0f0f8383e");

    // TODO: activate $value_tester_email after Review


    //TODO: Email verschicken, Liste nach email Adressen suchen nicht nach Ticket
    //recreate the given array and sort them by tester, owner etc. to prevent sending multiple mails
    $categories = getAllCategories($newIdsSinceLastRun[0]);
    $involedEmails = getAllInvolvedMails($newIdsSinceLastRun);


    $mailCategory=[];
    foreach ($involedEmails as $mail) {
        foreach ($categories as $category) {
            $mailCategory[$mail][$category] = array_keys(
                array_column($newIdsSinceLastRun, $category, 'ticket'),
                $mail
            );
        }
    }

    foreach ($mailCategory as $email => $body) {
        if (empty($email)) {
            continue;
        }

        $mailBody = [
            "subject_name"                   => "Every Ticket you are involved in, which is in 'Ready to Test' state",
            "body_tester"                    => "The Tickets you have to test",
            "body_author"                    => "The Tickets you created and are in the 'Ready to Test' state",
            "body_assigned_to"               => "The Tickets you worked on and put in the 'Ready to Test' state",
            "commenter_name"                 => "commenter_name_Value",
        ];



        foreach ($body as $categoryName => $issueIds) {
            foreach ($issueIds as $issueId) {
                $attachmentCategory              = 'attachment_details_' . $categoryName;
                $mailBody[$attachmentCategory][] = getMailAttachmentById($issueId);
            }
        }

        $client->sendEmailWithTemplate(
            "robot@kautionsfrei.de",
            $email,
            9276967,
            $mailBody
        );
    }
}

/**
 * @param $issueId
 *
 * @return array
 */
function getMailAttachmentById($issueId)
{
    return [
        "attachment_url"  => "https://kautionsfrei.plan.io/issues/" . $issueId,
        "attachment_name" => "https://kautionsfrei.plan.io/issues/" . $issueId
    ];
}

/**
 * @param $issueId
 *
 * @return string
 */


/**
 * @param $newIdsSinceLastRun
 */
function processMail($newIdsSinceLastRun)
{

    $timestamp = time();
    $date = date("Y-m-d H:i:s", $timestamp);
    $lastRunFile = '/tmp/testerNotify.CheckDateToSendNightlyMails.txt';

    $datetime = explode(" ", $date);
    $dateNow = $datetime[0];
    $timeNow = $datetime[1];


    if (file_exists($lastRunFile)) {
        $getDateTimeLastRun = file_get_contents($lastRunFile);
        if (strtotime($dateNow) > strtotime($getDateTimeLastRun)) {
            sendEmailMessages($newIdsSinceLastRun);
        }
    } elseif (strtotime($timeNow)<= "01:00:00") {
        sendEmailMessages($newIdsSinceLastRun);
    }

    file_put_contents($lastRunFile, $date);
}
