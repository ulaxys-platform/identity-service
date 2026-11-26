<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Password Hashing Driver
    |--------------------------------------------------------------------------
    |
    | The Identity service mandates Argon2id for new and updated password
    | hashes. Parameters follow the OWASP Password Storage Cheat Sheet
    | minimum (memory 19 MiB, time cost 2, threads 1). Existing hashes are
    | opportunistically rehashed on successful login via Hash::needsRehash.
    |
    */

    'driver' => 'argon2id',

    'argon' => [
        'memory' => 19456,
        'threads' => 1,
        'time' => 2,
    ],

    'bcrypt' => [
        'rounds' => env('BCRYPT_ROUNDS', 12),
    ],

    'verify' => [
        // Deterministic rehash timing is not required; verification uses the
        // stored hash parameters. Keep the timeout generous enough for the
        // Argon2id parameters above under container CPU limits.
        'timeout' => 6,
    ],

];
