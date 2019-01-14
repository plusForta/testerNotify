<?php
/**
 * Created by PhpStorm.
 * User: fatih
 * Date: 22.11.18
 * Time: 08:38
 */
require_once './vendor/autoload.php';
require 'helper_functions.php';

$options = getopt('v::');

$debug = array_key_exists('v', $options);

//create an array of Ticket Issues to force an api Call in line 33f. & 40f.
$testLimit = [
    6061,
    6062,
    6149,
];

if ($debug && count($testLimit) > 0) {
    echo "Testing limited to ticket #: ";
    foreach ($testLimit as $ticket) {
        echo $ticket . " ";
    }
    echo PHP_EOL;
}

$state = getLastRunTimes();

// get all tickets that are "ready to test" in the entwicklung project
// that have changed since the last run.

$slackWork = getTicketsToProcess($state['lastRun']);
if (sendSlackMessages($slackWork)) {
    $state['lastRun'] = date('U');
}

// if this is the first run of the day, send an email!
if (date('d') !== date('d', $state['lastMail'])) {
    $emailWork = getTicketsToProcess($state['lastMail']);
    if (sendEmailMessages($emailWork)) {
        $state['lastMail'] = date('U');
    }
}


die();


/*****
 * OLD STUFF
 */
//$planioIssues        = getPlanioIssuesArrayFromResponse($tickets);
//$currentIssuesToTest = replaceUserIdsWithEmails($planioIssues);
//if (count($currentIssuesToTest) == 0) {
//    // mail no new stuff
//    return;
//}
//
//$issuesForSlackNotification = $currentIssuesToTest;
//
//
//// slacken
//if (count($issuesForSlackNotification) > 0) {
//    sendSlackMessages($issuesForSlackNotification);
//}
//
//// process mail
//processMail($currentIssuesToTest);
//
//// save the file for future runs.
//file_put_contents($lastRunFile, json_encode($currentIssuesToTest));
//
//
//
//
