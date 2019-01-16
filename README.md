# testernotify

We use this script to check a custom field on our planio system and to notify
users when:
* They have a ticket assigned for them to test
* They have set a ticket as ready to test, but not specified a tester

It should notify users in slack the first time it encounters a change.
It should email everyone the status of their tickets nightly.

## Dependencies

We use the following APIs:
* Redmine/Planio (naturally)
* Slack
* Postmark (postmarkapp.com)

The PHP version is 7.2

## Installing

check out the repo.

1. ```composer install```
1. ```cp env.example .env```
1. edit the .env file with your credentials
1. set up a cron to run the script as often as you like

## Running the script

```php testernotify.php```

There are no required parameters, but you can optionally give:

| option | result |
| --- | --- |
| -v | Runs in verbose mode, doesn't actually send messages |
| -n | Doesn't save state at the end of the run, so you can run the command multiple times |

