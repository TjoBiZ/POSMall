<?php

return [
    'customer_groups' => [
        'silver' => [
            'name' => 'Silver Partner',
        ],
        'gold' => [
            'name' => 'Gold Partner',
        ],
        'diamond' => [
            'name' => 'Diamond Partner',
        ],
    ],
    'customers' => [
        'normal' => 'Normal Customer',
        'gold' => 'Gold Customer',
        'diamond' => 'Diamond Customer',
    ],
    'notifications' => [
        'admin_checkout_succeeded' => [
            'name' => 'Admin notification: Checkout succeeded',
            'description' => 'Sent to the shop admin when a checkout succeeded',
        ],
        'admin_checkout_failed' => [
            'name' => 'Admin notification: Checkout failed',
            'description' => 'Sent to the shop admin when a checkout failed',
        ],
        'customer_created' => [
            'name' => 'Customer signed up',
            'description' => 'Sent when a customer has signed up',
        ],
        'checkout_succeeded' => [
            'name' => 'Checkout succeeded',
            'description' => 'Sent when a checkout was successful',
        ],
        'checkout_failed' => [
            'name' => 'Checkout failed',
            'description' => 'Sent when a checkout has failed',
        ],
        'order_shipped' => [
            'name' => 'Order shipped',
            'description' => 'Sent when the order has been marked as shipped',
        ],
        'order_state_changed' => [
            'name' => 'Order status changed',
            'description' => 'Sent when an order status was updated',
        ],
        'payment_paid' => [
            'name' => 'Payment received',
            'description' => 'Sent when a payment has been received',
        ],
        'payment_failed' => [
            'name' => 'Payment failed',
            'description' => 'Sent when a payment has failed',
        ],
        'payment_refunded' => [
            'name' => 'Payment refunded',
            'description' => 'Sent when a payment has been refunded',
        ],
    ],
    'order_states' => [
        'new' => 'New',
        'in_progress' => 'In Progress',
        'disputed' => 'Disputed',
        'cancelled' => 'Cancelled',
        'complete' => 'Complete',
    ],
    'payment_methods' => [
        'invoice' => 'Invoice',
    ],
    'price_categories' => [
        'old_price_name' => 'Old Price',
        'old_price_label' => 'Original Pricing',
        'msrp_price_name' => 'MSRP',
        'msrp_price_label' => 'Manufacturer suggested retail price',
    ],
    'shipping_methods' => [
        'standard' => 'Standard',
        'express' => 'Express',
    ],
    'taxes' => [
        'standard' => 'Standard',
        'reduced' => 'Reduced',
    ],
];
