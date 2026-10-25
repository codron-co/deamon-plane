<?php

namespace Tests\Unit;

use App\Services\Databases\RedisServerClient;
use App\Services\Databases\RedisServerException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class RedisAclRuleTest extends TestCase
{
    public function test_site_user_is_limited_to_its_prefix_and_safe_commands(): void
    {
        $args = RedisServerClient::aclSetUserArgs('site_izyem', 'S3cretPassw0rd', 'site_izyem:');

        $this->assertSame(
            'site_izyem on resetpass >S3cretPassw0rd resetkeys ~site_izyem:* resetchannels &site_izyem:* nocommands +@all -@dangerous -flushdb -flushall -keys',
            implode(' ', $args),
        );
    }

    public function test_reset_comes_before_grant_so_old_rules_never_survive(): void
    {
        $args = RedisServerClient::aclSetUserArgs('site_a', 'pw', 'site_a:');

        $this->assertLessThan(array_search('>pw', $args, true), array_search('resetpass', $args, true));
        $this->assertLessThan(array_search('~site_a:*', $args, true), array_search('resetkeys', $args, true));
        $this->assertLessThan(array_search('&site_a:*', $args, true), array_search('resetchannels', $args, true));
        $this->assertLessThan(array_search('+@all', $args, true), array_search('nocommands', $args, true));
        $this->assertLessThan(array_search('-@dangerous', $args, true), array_search('+@all', $args, true));
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function unsafe(): array
    {
        return [
            'user with a space' => ['site izyem', 'pw', 'site_izyem:'],
            'user with a glob' => ['site_*', 'pw', 'site_x:'],
            'prefix without colon' => ['site_izyem', 'pw', 'site_izyem'],
            'prefix with a glob' => ['site_izyem', 'pw', 'site_*:'],
            'empty prefix' => ['site_izyem', 'pw', ''],
            'empty password' => ['site_izyem', '', 'site_izyem:'],
            'password with whitespace' => ['site_izyem', 'a b', 'site_izyem:'],
        ];
    }

    #[DataProvider('unsafe')]
    public function test_unsafe_input_is_refused(string $user, string $password, string $prefix): void
    {
        $this->expectException(RuntimeException::class);

        RedisServerClient::aclSetUserArgs($user, $password, $prefix);
    }

    public function test_errors_never_carry_the_passwords(): void
    {
        $e = new RedisServerException('ERR Error in ACL SETUSER modifier \'>hunter2-site\' for admin-pw', ['hunter2-site', 'admin-pw']);

        $this->assertStringNotContainsString('hunter2-site', $e->getMessage());
        $this->assertStringNotContainsString('admin-pw', $e->getMessage());
    }
}
