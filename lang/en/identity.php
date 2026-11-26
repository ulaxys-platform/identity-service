<?php

// English service messages. Stable machine-readable identifiers are never
// translated; only human-facing text lives here.
return [
    'auth' => [
        'invalid_credentials' => 'Invalid credentials.',
        'authentication_required' => 'Authentication required.',
        'invalid_refresh_token' => 'Invalid or expired refresh token.',
    ],
    'sessions' => [
        'revoked' => 'Session revoked.',
        'revoked_all' => 'Other sessions revoked.',
    ],
    'credentials' => [
        'current_password_invalid' => 'The current password is incorrect.',
        'password_changed' => 'Password changed. Other sessions were revoked.',
        'recovery_requested' => 'If the account exists, recovery instructions have been sent.',
        'recovery_completed' => 'Password reset completed. All sessions were revoked.',
        'challenge_invalid' => 'The token is invalid, expired or already used.',
        'account_setup_completed' => 'Account activated. You can now sign in.',
        'verification_requested' => 'If unverified email addresses exist, verification instructions have been sent.',
        'verification_completed' => 'Email address verified.',
        'policy' => [
            'too_short' => 'The password must be at least :min characters.',
            'too_long' => 'The password must not exceed :max characters.',
            'blocklisted' => 'This password is commonly used and not allowed.',
            'identifier_similar' => 'The password must not contain your login identifier or display name.',
        ],
    ],
    'profile' => [
        'updated' => 'Profile updated.',
    ],
    'admin' => [
        'identifier_conflict' => 'This login identifier is already in use.',
    ],
    'oauth' => [
        'token_revoked' => 'Token revoked.',
    ],
    'delivery' => [
        'password_reset' => [
            'subject' => 'uLaxys password reset',
            'body' => 'Use this single-use token within its validity window to reset your password: :token',
        ],
        'email_verification' => [
            'subject' => 'uLaxys email verification',
            'body' => 'Use this single-use token to verify your email address: :token',
        ],
        'account_setup' => [
            'subject' => 'uLaxys account activation',
            'body' => 'Use this single-use token to activate your account and choose a password: :token',
        ],
    ],
];
