<?php

declare(strict_types=1);

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Adapter for a single user in a disposable R0 realm. */
final readonly class Provisioner
{
    private const USERNAME = 'r0-user';

    public function __construct(
        private PendingRequest $admin,
        private string $adminUrl,
        private string $issuer,
        private string $table,
    ) {}

    public function provision(#[SensitiveParameter] string $password): object
    {
        $users = $this->findFixtureUser();
        if ($users === []) {
            $created = $this->admin->post("$this->adminUrl/users", [
                'username' => self::USERNAME,
                'firstName' => 'Runtime',
                'lastName' => 'Fixture',
                'email' => 'identity-r0@example.test',
                'emailVerified' => true,
                'enabled' => true,
                'credentials' => [['type' => 'password', 'value' => $password, 'temporary' => false]],
            ]);
            expect(in_array($created->status(), [201, 409], true), 'Provider user creation failed');
            $users = $this->findFixtureUser();
        }

        expect(count($users) === 1, 'Provisioning must resolve one subject');
        $identity = ['issuer' => $this->issuer, 'subject' => $users[0]['id']];
        DB::table($this->table)->insertOrIgnore([...$identity, 'ulid' => (string) Str::ulid()]);
        $mapping = DB::table($this->table)->where($identity)->first();
        expect($mapping !== null, 'Provider subject has no platform mapping');

        return $mapping;
    }

    private function findFixtureUser(): array
    {
        return $this->admin->get("$this->adminUrl/users", [
            'username' => self::USERNAME,
            'exact' => 'true',
        ])->throw()->json();
    }
}
