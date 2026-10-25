<?php
// Copy one site's Redis keys between its own Redis and the shared deamon-redis, keeping TTLs
// and swapping the key prefix. Runs inside the site's app container (it reaches both `redis`
// on its stack network and `deamon-redis` on deamon-db). Called by move-site-redis.sh.
//
// stdin: one JSON object
//   {"direction":"to_shared"|"to_local",
//    "local":{"host":"redis","port":6379,"dbs":[0,1],"prefix":"deamon-database-"},
//    "shared":{"host":"deamon-redis","port":6379,"dbs":[N,N+1],"prefix":"site_x:","user":"plane_admin","pass":"..."}}
// stdout: "db <from>-><to> copied=<n> skipped_expired=<n> target_count=<n>" per DB pair, then "OK" or "FAIL ...".
// The target DB pair must not hold keys with the target prefix (re-runs after a rollback are
// handled by the caller clearing them first). Never prints passwords.

$cfg = json_decode((string) stream_get_contents(STDIN), true);
if (! is_array($cfg) || ! in_array($cfg['direction'] ?? '', ['to_shared', 'to_local'], true)) {
    fwrite(STDOUT, "FAIL bad input\n");
    exit(2);
}

function conn(array $c): Redis
{
    $r = new Redis;
    if (! $r->connect($c['host'], (int) $c['port'], 5.0)) {
        throw new RuntimeException('connect failed: '.$c['host']);
    }
    if (($c['pass'] ?? '') !== '') {
        if (! $r->auth(($c['user'] ?? '') !== '' ? [$c['user'], $c['pass']] : $c['pass'])) {
            throw new RuntimeException('auth failed: '.$c['host']);
        }
    }

    return $r;
}

[$src, $dst] = $cfg['direction'] === 'to_shared' ? [$cfg['local'], $cfg['shared']] : [$cfg['shared'], $cfg['local']];

try {
    $from = conn($src);
    $to = conn($dst);
    $ok = true;

    foreach ([0, 1] as $i) {
        $sdb = (int) $src['dbs'][$i];
        $ddb = (int) $dst['dbs'][$i];
        $from->select($sdb);
        $to->select($ddb);

        $existing = 0;
        $it = null;
        while (($keys = $to->scan($it, $dst['prefix'].'*', 500)) !== false) {
            $existing += count($keys);
        }
        if ($existing > 0) {
            fwrite(STDOUT, "FAIL target db $ddb already has $existing keys with prefix {$dst['prefix']}\n");
            exit(3);
        }

        $copied = 0;
        $expired = 0;
        $it = null;
        while (($keys = $from->scan($it, $src['prefix'].'*', 500)) !== false) {
            foreach ($keys as $key) {
                $ttl = $from->pttl($key);
                if ($ttl === -2) {
                    $expired++;

                    continue;
                }
                $payload = $from->dump($key);
                if ($payload === false) {
                    $expired++;

                    continue;
                }
                $newKey = $dst['prefix'].substr($key, strlen($src['prefix']));
                if (! $to->restore($newKey, $ttl > 0 ? $ttl : 0, $payload, ['REPLACE'])) {
                    throw new RuntimeException("restore failed for a key in db $ddb");
                }
                $copied++;
            }
        }

        $count = 0;
        $it = null;
        while (($keys = $to->scan($it, $dst['prefix'].'*', 500)) !== false) {
            $count += count($keys);
        }
        // Keys can expire between the copy and the count; fewer is fine, more is not.
        $line = "db $sdb->$ddb copied=$copied skipped_expired=$expired target_count=$count";
        if ($count > $copied || $count < $copied - 50) {
            $ok = false;
            $line .= ' MISMATCH';
        }
        fwrite(STDOUT, $line."\n");
    }

    fwrite(STDOUT, $ok ? "OK\n" : "FAIL count mismatch\n");
    exit($ok ? 0 : 4);
} catch (Throwable $e) {
    fwrite(STDOUT, 'FAIL '.$e->getMessage()."\n");
    exit(1);
}
