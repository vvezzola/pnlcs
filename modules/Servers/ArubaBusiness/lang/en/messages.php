<?php

return [
    'title' => 'Aruba Business',
    'autorenew' => [
        'on' => 'on',
        'off' => 'off',
    ],
    'field' => [
        'account' => 'Mailbox',
        'domain' => 'Certified domain',
        'article' => 'Type',
        'state' => 'State at Aruba',
        'activated' => 'Active since',
        'expires' => 'Expires at Aruba',
        'autorenew' => 'Automatic renewal',
        'renewal_price' => 'Renewal price at Aruba',
        'service_id' => 'Aruba service',
        'checked_at' => 'Read back on',
        'webmail' => 'Webmail',
        'manage' => 'Mailbox settings',
    ],
    'action' => [
        'check_now' => 'Read back from Aruba',
    ],
    'client' => [
        'webmail_link' => 'Open the PEC webmail',
        'manage_link' => 'Password and settings',
        'not_linked' => 'This service is being set up.',
    ],
    'admin' => [
        'not_linked' => 'Not linked to a service of the Aruba Business account yet. Link it from the server sync page, or with "link existing account".',
    ],
    'warning' => [
        'state' => 'Aruba reports this service as ":state".',
        'autorenew_off' => 'Automatic renewal is off at Aruba: the service ends on :date unless it is renewed in the Aruba panel.',
        'expires_first' => 'Aruba ends this service on :date, before its next due date here.',
    ],
    'todo' => [
        'autorenew_off' => 'Turn off automatic renewal for :account in the Aruba Business panel: the service has ended here, and Aruba renews it on :date.',
    ],
    'result' => [
        'linked' => 'Linked to the Aruba Business service.',
        'billing_only' => 'Billing only: nothing changes at Aruba.',
        'nothing_linked' => 'No Aruba service is linked: nothing to do.',
        'ended' => 'Service ended; Aruba no longer renews it.',
        'refreshed' => 'Read back from Aruba.',
        'renews_itself' => 'Aruba renews by itself. It expires on :date; automatic renewal is :autorenew.',
    ],
    'error' => [
        'no_server' => 'No Aruba Business account is set up (Setup, Servers).',
        'no_credentials' => 'The Aruba Business server record needs a username, a password and the Authorization-Key in the API key field.',
        'create_by_hand' => 'A mailbox is created in the Aruba Business panel, then linked here.',
        'password_by_hand' => 'The password is changed from the mailbox settings at Aruba.',
        'package_by_hand' => 'The type is changed in the Aruba Business panel.',
        'not_linked' => 'The service is not linked to an Aruba service.',
        'not_found' => 'The Aruba Business account has no service :id among its PEC services.',
        'unreachable' => 'Aruba Business could not be reached.',
        'refused' => 'Aruba Business refused the request (:status).',
        'sign_in' => 'Aruba Business refused the sign-in (:status): check the username, the password and the Authorization-Key.',
    ],
];
