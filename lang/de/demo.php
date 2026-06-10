<?php

return [
    'customer_groups' => [
        'silver' => [
            'name' => 'Silber-Partner',
        ],
        'gold' => [
            'name' => 'Gold-Partner',
        ],
        'diamond' => [
            'name' => 'Diamant-Partner',
        ],
    ],
    'customers' => [
        'normal' => 'Normaler Kunde',
        'gold' => 'Gold-Kunde',
        'diamond' => 'Diamant-Kunde',
    ],
    'notifications' => [
        'admin_checkout_succeeded' => [
            'name' => 'Admin-Benachrichtigung: Checkout erfolgreich',
            'description' => 'Wird an den Shop-Administrator gesendet, wenn ein Checkout erfolgreich war',
        ],
        'admin_checkout_failed' => [
            'name' => 'Admin-Benachrichtigung: Checkout fehlgeschlagen',
            'description' => 'Wird an den Shop-Administrator gesendet, wenn ein Checkout fehlgeschlagen ist',
        ],
        'customer_created' => [
            'name' => 'Kunde registriert',
            'description' => 'Wird gesendet, wenn ein Kunde sich registriert hat',
        ],
        'checkout_succeeded' => [
            'name' => 'Checkout erfolgreich',
            'description' => 'Wird gesendet, wenn ein Checkout erfolgreich war',
        ],
        'checkout_failed' => [
            'name' => 'Checkout fehlgeschlagen',
            'description' => 'Wird gesendet, wenn ein Checkout fehlgeschlagen ist',
        ],
        'order_shipped' => [
            'name' => 'Bestellung versandt',
            'description' => 'Wird gesendet, wenn eine Bestellung als versendet markiert wurde',
        ],
        'order_state_changed' => [
            'name' => 'Bestellstatus aktualisiert',
            'description' => 'Wird gesendet, wenn der Bestellstatus aktualisiert wurde',
        ],
        'payment_paid' => [
            'name' => 'Zahlung erhalten',
            'description' => 'Wird gesendet, wenn eine Zahlung eingegangen ist',
        ],
        'payment_failed' => [
            'name' => 'Zahlung fehlgeschlagen',
            'description' => 'Wird gesendet, wenn eine Zahlung fehlgeschlagen ist',
        ],
        'payment_refunded' => [
            'name' => 'Zahlung erstattet',
            'description' => 'Wird gesendet, wenn eine Zahlung erstattet wurde',
        ],
    ],
    'order_states' => [
        'new' => 'Neu',
        'in_progress' => 'In Bearbeitung',
        'disputed' => 'Reklamiert',
        'cancelled' => 'Storniert',
        'complete' => 'Abgeschlossen',
    ],
    'payment_methods' => [
        'invoice' => 'Auf Rechnung',
    ],
    'price_categories' => [
        'old_price_name' => 'Alter Preis',
        'old_price_label' => 'Ursprungspreis',
        'msrp_price_name' => 'UVP',
        'msrp_price_label' => 'Unverbindliche Preisempfehlung',
    ],
    'shipping_methods' => [
        'standard' => 'Standard',
        'express' => 'Express',
    ],
    'taxes' => [
        'standard' => 'Standard',
        'reduced' => 'Ermaessigt',
    ],
];
