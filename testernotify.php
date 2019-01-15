<?php
/**
 * Created by PhpStorm.
 * User: fatih
 * Date: 22.11.18
 * Time: 08:38
 */
require_once './vendor/autoload.php';
require 'helper_functions.php';

// load environment variables.
$dotenv = Dotenv\Dotenv::create(__DIR__);
$dotenv->load();

$options = getopt('v::n::');

$debug = array_key_exists('v', $options);

$nosave = array_key_exists('n', $options);

$state = getState();
if (array_key_exists('lookup', $state) && count($state['lookup']) > 0) {
    $lookup = $state['lookup'];
} else {
    $lookup = buildUserLookupTable();
}


// get all tickets that are "ready to test" in the Entwicklung project
// that have changed since the last run.
if ($debug) {
    echo "Processing tickets for slack..." . PHP_EOL;
}
$work = getTicketsToProcess(intval($state['lastRun']), $lookup);

if ($debug) {
    echo "Sending slack messages" . PHP_EOL;
}
sendSlackMessages($work);
$state['lastRun'] = time();

// if this is the first run of the day, do it again, but for emails.
if (date('d') !== date('d', $state['lastMail'])) {

    if ($debug) {
        echo "Processing tickets for postmark..." . PHP_EOL;
    }
    $fullDay = getTicketsToProcess(intval($state['lastMail']), $lookup);

    if ($debug) {
        echo "Sending Email messages" . PHP_EOL;
    }
    sendEmailMessages($fullDay);

    $state['lastMail'] = time();
    // regen the user lookup table
    if ($debug) {
        echo "Regenerate the lookup table." . PHP_EOL;
    }
    $state['lookup'] = buildUserLookupTable();

}

if (!$nosave) {
    if ($debug) {
        echo "Saving state... " . PHP_EOL;
    }
    file_put_contents(getenv('RUNFILE'), json_encode($state));
}
