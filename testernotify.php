<?php
/**
 * Created by PhpStorm.
 * User: fatih
 * Date: 22.11.18
 * Time: 08:38
 */
require_once './vendor/autoload.php';
require 'helper_functions.php';


//create an array of Ticket Issues to force an api Call in line 33f. & 40f.
$extracted_id_Test = array(
    array (
        'ticket' => 6082,
        'tester' => "fatih.doenmez@kautionsfrei.de",
        'owner' => "fatih.doenmez@kautionsfrei.de",
    ),
);

$lastRunFile = '/tmp/testerNotify.Tickets.txt';

// get all tickets that are "ready to test" in the entwicklung project
$response = getJsonObjFromPlanioURL(
    'https://kautionsfrei.plan.io/issues',
    '2d9977f1c2578de68068616410e78a8a05fac126',
    [
        'status_id'  => 14,
        'project_id' => 134
    ]
);

//checkout if the file exists if not create

$planioIssues        = getPlanioIssuesArrayFromResponse($response);
$currentIssuesToTest = replaceUserIdsWithEmails($planioIssues);
if (count($currentIssuesToTest) == 0) {
    // mail no new stuff
    return;
}

$issuesForSlackNotification = $currentIssuesToTest;

if (file_exists($lastRunFile)) { // file exists, pull any processed tickets out of the set from planio
    $issuesLastRun = json_decode(file_get_contents($lastRunFile), true);

    // TODO: Replace '$extracted_id_Test' with '$currentIssuesToTest' after Review
    $issuesForSlackNotification = \Rogervila\ArrayDiffMultidimensional::compare($currentIssuesToTest, $issuesLastRun);
}

// slacken
if (count($issuesForSlackNotification) > 0) {
    sendSlackMessages($issuesForSlackNotification);
}

// process mail
processMail($currentIssuesToTest);

// save the file for future runs.
file_put_contents($lastRunFile, json_encode($currentIssuesToTest));




