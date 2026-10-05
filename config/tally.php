<?php

declare(strict_types=1);

return [

    /*
     * Days between sending an invoice and its due date, when none was set.
     */
    'payment_terms_days' => (int) env('TALLY_PAYMENT_TERMS_DAYS', 30),

    /*
     * Minimum days between two overdue reminders for the same invoice.
     */
    'reminder_interval_days' => (int) env('TALLY_REMINDER_INTERVAL_DAYS', 7),

    'webhooks' => [
        'timeout_seconds' => (int) env('TALLY_WEBHOOK_TIMEOUT', 5),
    ],

];
