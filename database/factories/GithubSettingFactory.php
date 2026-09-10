<?php

namespace Database\Factories;

use App\Models\GithubSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GithubSetting>
 */
class GithubSettingFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'org' => 'deamon-themes',
            'token' => null,
            'app_id' => null,
            'installation_id' => null,
            'slug' => null,
            'client_id' => null,
            'client_secret' => null,
            'private_key' => null,
            'webhook_secret' => null,
        ];
    }

    public function withToken(string $token = 'github-test-token'): static
    {
        return $this->state(fn (array $attributes) => [
            'token' => $token,
        ]);
    }

    public function withApp(?string $installationId = '1001'): static
    {
        return $this->state(fn (array $attributes) => [
            'app_id' => '42',
            'installation_id' => $installationId,
            'slug' => 'deamon-plane-themes',
            'client_id' => 'Iv1.test',
            'client_secret' => 'github-app-client-secret',
            'private_key' => self::testPrivateKey(),
            'webhook_secret' => 'gh-hook-secret',
        ]);
    }

    public static function testPrivateKey(): string
    {
        return <<<'PEM'
-----BEGIN PRIVATE KEY-----
MIIEvQIBADANBgkqhkiG9w0BAQEFAASCBKcwggSjAgEAAoIBAQDMDKSsAzkPOHMz
7hEsge8kfOn0QRYG/odQbEnZFqh6jzAjEOVpyT+kKu8DHK6O/RHu0S77ZOYk/8zq
ggYxO2sU7a95WoIOVhiVOc69Dh8dKaGWhmPBiriArNTHxP4ENT0aq+LRcNrIItar
Xpg309BAi6xb+bcu7WD8v5z8Zu4usH/9FFSoSdL0wilCKDK7qBQzupTb6zLymbxo
nQ227Am79kOna1x9nmPUJyYaXt826p+QRM9j4exHM4StWenGJdt8q8nQpEeDbZP9
Lt2GxKT2ppFqY2aguaAGekSyIp4LSDY8AZKigW3v+uzNlZQj4kDy7MIjZgMj3QPn
Losl/iWNAgMBAAECggEAEOrNbkt4HBhwiLIor1l4c7x9gxgwVNUeb98CgmKcBkk/
0vBwLMSYJ6q9lTL4D/fQWE30iLg1UoT4vsqX69YCy0HnhfaoiLq/oAOibc8xajAM
6xdqRt4S7OwnWgatjrjBP6hXjki+gtBMnvmhQiOoBOnvmNKLyvK4U0wpnk2EWs92
Tg/JamqKmc83Knx60WBPXySRqGADxwZFFgO7WNYO1O1XzbHXGYk+HOiMdz7TenyO
ZanTgm1SemiYK+vPG6DmSrFG/dCSj60y9416O0ZMNlk5ENSh+dgNB7BUKS1dmzK/
vIWPJnBYt7UYUT35f2lBmKoatF77OzGFb2Kve3NEMQKBgQD4OE8BzX9sm/xYwKUx
zp7ZGJJiVt8aE+hPUBJ2RmFCxeKLqjOAD2P1kY6lU4O1WppMGTkivdamnIH9O844
vVehzqtOQEz8fQ42Ps4A64upiwTQYdTzpPQFKoiomJzJzCzrJaZQrIF1aHbc4rLL
ksvc+E9+cETb3N4LoqngPhahSQKBgQDSceofIuD3lg348dnSjEFtc2gMN78srhFr
Amfl1gJM4vy3Nb/kYBPcJid0y7H5b2HJpJhsNO22PMtGEhFbE2f3OC/xRtCYxtNV
Ul6cQj6K1Pv7yJCUFTyBf/tSk4av9wD1g3YO3VvciloCwauBkdRPrROxyQ2GcQ7R
Zu4eEogmJQKBgHHDtQpZeh54O6cd3FjAn3NW3Livoh9comu/gkatKSSmd5eVkXcP
FrxVUzCY31O+S9u278XphjjkoHtE7tZ4iXKCu2bo95/9XQclr9siGefB7JnpTOXC
Y4j+npXPJIUkzC2WGuz8s3TxRREl4daF2GPVdvG3WQf/6dEhY4SAUHTpAoGALDDj
mvo2B4epE3el6AKv0o4DcV1bdcRvv+rXanoQLZkUvFw7GXfbc8VHT81eaCStgixg
HYjXygbmIKa2oktm75EK8D2QnCRUSHxthZ6bh4fGCk9JnO8Ar6jyW5rDE7xopSWf
6uss2RjsYdvNaf33eWu80P6JKowfMnXM1t/JW0kCgYEAq8damRg1B/XTniXf2LVe
MHtggoZgHvBCAHEnFMXmF0dLTtu3N7I9nZhB9MW/uiTV1qc5WYES5/KCqEPhsRkP
ERomXMek1AJoYc136wSQCDyiE4hBuLein/2FZWmzD/uTqgCqapIK9weHLwi4PVhQ
gp34LkiLwm3/X7XEMvBvoI4=
-----END PRIVATE KEY-----
PEM;
    }
}
