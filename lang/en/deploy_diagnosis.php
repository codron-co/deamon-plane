<?php

return [
    'title' => 'Diagnosis',
    'kicker' => 'What Plane found',
    'hint' => 'The Coolify output was classified automatically. Safe fixes run on their own; the rest is listed as steps and server commands below.',
    'evidence' => 'Evidence line',
    'cause' => 'Cause',
    'steps' => 'What to do',
    'commands' => 'Run on the server (whoever has server access)',
    'container_logs' => 'Container log (Coolify API, last lines)',
    'container_logs_missing' => 'Container log unavailable: :reason',
    'container_logs_pending' => 'Container log not fetched yet. “Refresh diagnosis” pulls the last lines from Coolify.',
    'refresh' => 'Refresh diagnosis',
    'refreshed' => 'Diagnosis refreshed.',
    'runbook' => 'Runbook: docs/runbooks/deploy-failure-triage.md#:code',
    'fix_buttons' => 'Apply from Plane',
    'auto_none' => 'No automatic fix for this failure; the steps below are needed.',
    'auto' => [
        'applied' => 'Plane applied automatically: :fix. See the outcome on the next deployment row.',
        'skipped' => 'Automatic fix (:fix) skipped: :reason',
        'failed' => 'Automatic fix (:fix) could not run: :reason',
    ],
    'skip_reasons' => [
        'repeated' => 'it was already applied for the same failure a moment ago; retrying would loop.',
        'newer_deployment' => 'a newer deployment started in the meantime.',
        'disabled' => 'automatic fixes are disabled in settings.',
        'not_safe' => 'this fix needs an operator decision.',
        'no_app' => 'the site has no Coolify application linked.',
        'error' => 'Coolify returned an error.',
    ],
    'services' => [
        'mysql' => 'MySQL',
        'redis' => 'Redis',
        'app' => 'Application (CMS)',
        'build' => 'Build',
        'git' => 'Git',
        'coolify' => 'Coolify',
        'host' => 'Server',
        'unknown' => 'Unknown',
    ],
    'codes' => [
        'unknown' => [
            'title' => 'Failure not classified',
            'cause' => 'No known pattern in the Coolify output. Read the container log and the full report.',
            'steps' => [
                'Paste the “Copy report” output from this page to a developer or an AI.',
                'Open the application in Coolify and read the last lines of the app / mysql / redis containers under Logs.',
                'Once the cause is known, add the pattern to DeploymentFailureClassifier so Plane recognises it next time.',
            ],
            'commands' => [
                'docker ps -a --filter name=:uuid --format "{{.Names}}\t{{.Status}}"',
                'docker logs :container --tail 80',
            ],
        ],
        'mysql_exited' => [
            'title' => 'MySQL container exited right after start (exit :exit)',
            'cause' => 'The app does not start until MySQL is healthy. MySQL exited within a second; the reason is in MySQL’s own log, not in the Coolify deploy log. The code did not change: if the same commit runs elsewhere, the problem is this site’s volume, env or host resources.',
            'steps' => [
                'Read the container log below (Plane fetched it when it could; otherwise run the command on the server). The [ERROR] line names the cause.',
                '“password option is not specified” → env is empty: Plane “Fix env” + “Redeploy”.',
                '“initialized by a newer version” → the volume was created by a newer MySQL: match the compose image to the volume (runbook).',
                '“Unable to lock ./ibdata1” → an old container holds the volume: “Stop and redeploy”.',
                '“No space left on device” → host disk is full (runbook: disk cleanup).',
                'Do NOT delete the volume: customer data would be lost. Only a fresh/empty site may have its volume removed in Coolify and be redeployed.',
            ],
            'commands' => [
                'docker logs :container --tail 80',
                'docker ps -a --filter name=:uuid --format "{{.Names}}\t{{.Status}}"',
                'df -h /var/lib/docker',
            ],
        ],
        'redis_exited' => [
            'title' => 'Redis container did not start (exit :exit)',
            'cause' => 'Redis usually dies from its memory cap (128M) or a full disk; the app does not start without it.',
            'steps' => [
                'Read the container log; “Can’t save in background” / “MISCONF” means disk, “OOM” means memory.',
                'If the disk is full apply the runbook cleanup, then “Redeploy”.',
            ],
            'commands' => [
                'docker logs :container --tail 60',
                'df -h /var/lib/docker',
                'free -m',
            ],
        ],
        'app_exited' => [
            'title' => 'Application container never became healthy',
            'cause' => 'The entrypoint exited at one step (DB/Redis wait, migrate, theme sync) or PHP did not come up. The reason is in the app container log.',
            'steps' => [
                'Read the last lines of the app container log; “Veritabanina … ulasilamadi” is DB, “SQLSTATE” is a migration error.',
                'A transient cause (DB came up late) needs only “Restart”; a persistent one follows the matching code’s steps.',
            ],
            'commands' => [
                'docker logs :container --tail 120',
            ],
        ],
        'mysql_no_root_password' => [
            'title' => 'MySQL env is empty: no root password',
            'cause' => 'The volume is empty (first boot) and MYSQL_ROOT_PASSWORD / DB_PASSWORD are missing or placeholders on the Coolify env. MySQL refuses to initialise without a password.',
            'steps' => [
                'Plane “Fix env” generates the passwords from the catalog and writes them to Coolify (shown below if it already ran).',
                'Then “Redeploy”.',
            ],
            'commands' => [],
        ],
        'mysql_data_newer_version' => [
            'title' => 'MySQL volume was created by a newer version',
            'cause' => 'The data directory on the volume was initialised by a MySQL newer than the compose image (mysql:8.0); the older server cannot open it and exits immediately.',
            'steps' => [
                'Read the version on the volume (command). If it shows 8.4.x the data was created by 8.4.',
                'Option A (fast, keeps data): override the mysql image for this app only in Coolify to match the volume, then deploy.',
                'Option B (clean): mysqldump with a temporary container at the volume’s version, delete the volume, start 8.0 empty, restore the dump.',
                'Do NOT delete the volume without a dump.',
            ],
            'commands' => [
                'docker logs :container --tail 40',
                'docker run --rm -v $(docker volume ls -q --filter name=:uuid | grep mysql):/var/lib/mysql alpine sh -c "cat /var/lib/mysql/mysql_upgrade_info 2>/dev/null || ls /var/lib/mysql | head"',
            ],
        ],
        'mysql_locked' => [
            'title' => 'MySQL volume is locked by another container',
            'cause' => 'The previous deploy’s mysql container is still running or did not shut down cleanly; the new one cannot take the ibdata1 lock.',
            'steps' => [
                'Plane “Stop and redeploy” stops the application in Coolify and starts it again.',
                'If still locked, find and stop the old mysql container on the server (command), then redeploy.',
            ],
            'commands' => [
                'docker ps --filter name=mysql-:uuid --format "{{.Names}}\t{{.Status}}"',
                'docker stop $(docker ps -q --filter name=mysql-:uuid)',
            ],
        ],
        'mysql_corrupt' => [
            'title' => 'MySQL data files are corrupt',
            'cause' => 'InnoDB recovery failed (usually a write cut short by an OOM kill or a full disk).',
            'steps' => [
                'Do NOT delete the volume. Locate the latest backup first (CMS backup module / Coolify backup).',
                'Try innodb_force_recovery=1..4 in a temporary container and take a mysqldump (runbook).',
                'If a dump was possible, reset the volume and restore; otherwise restore from backup.',
                'Fix the root cause: memory cap (mem_limit) and disk.',
            ],
            'commands' => [
                'docker logs :container --tail 120',
                'df -h /var/lib/docker && free -m',
            ],
        ],
        'git_access' => [
            'title' => 'Coolify could not access the repository',
            'cause' => 'GitHub App / deploy key lacks access, the token expired or the repository name is wrong.',
            'steps' => [
                'Check Plane Settings → Deamon Git and the Git source on the Coolify app (GitHub App / deploy key).',
                'In the Coolify UI verify repository and branch under Source; re-authorise the GitHub App if needed.',
                'Then “Redeploy”.',
            ],
            'commands' => [],
        ],
        'git_ref_missing' => [
            'title' => 'Pinned commit / branch does not exist',
            'cause' => 'Coolify could not find the pinned SHA or branch (force-push, deleted branch, wrong SHA).',
            'steps' => [
                'Use “Follow HEAD” to return to the branch tip, or pin a valid SHA under deploy settings.',
            ],
            'commands' => [],
        ],
        'registry_rate_limited' => [
            'title' => 'Docker Hub pull limit',
            'cause' => 'The server hit Docker Hub’s anonymous pull limit (mysql/redis image could not be pulled).',
            'steps' => [
                'Plane redeploys shortly. If it recurs, add a Docker Hub account in Coolify or cache the images.',
            ],
            'commands' => [],
        ],
        'disk_full' => [
            'title' => 'Server disk is full',
            'cause' => 'The build or a container got “No space left on device”. Old images, build cache and logs fill the disk; several sites fail at once.',
            'steps' => [
                'Check usage on the server and run the safe cleanup (commands). Do NOT delete volumes.',
                'After cleanup “Redeploy” the affected sites (bulk from the Sites list).',
                'Permanent fix: bigger disk or automatic image cleanup in Coolify.',
            ],
            'commands' => [
                'df -h && docker system df',
                'docker image prune -af --filter "until=72h" && docker builder prune -af --filter "until=72h"',
                'journalctl --vacuum-size=200M',
            ],
        ],
        'oom_killed' => [
            'title' => 'Container was killed for running out of memory (exit 137)',
            'cause' => 'The container exceeded its mem_limit (app 768M, mysql 768M) or the host ran out of memory. With restart: no it does not come back on its own.',
            'steps' => [
                '“Restart” brings the site back immediately.',
                'If it recurs raise mem_limit in the CMS compose or lower the number of concurrent builds on the server.',
            ],
            'commands' => [
                'docker inspect :container --format "{{.State.OOMKilled}} {{.State.ExitCode}}"',
                'free -m && dmesg | grep -i "killed process" | tail -5',
            ],
        ],
        'port_conflict' => [
            'title' => 'Port conflict',
            'cause' => 'An old container or process already listens on the same port.',
            'steps' => [
                '“Stop and redeploy”. If it persists, find the process holding the port on the server.',
            ],
            'commands' => [
                'docker ps --format "{{.Names}}\t{{.Ports}}" | grep -i :uuid',
            ],
        ],
        'docker_daemon' => [
            'title' => 'Docker daemon unreachable',
            'cause' => 'The Docker service on the server is not responding or the Coolify helper container could not start.',
            'steps' => [
                'Check the Docker service on the server, restart it if needed; then “Redeploy”.',
            ],
            'commands' => [
                'systemctl status docker --no-pager | head -20',
                'docker info | head -30',
            ],
        ],
        'app_db_unreachable' => [
            'title' => 'Application could not reach MySQL',
            'cause' => 'The app entrypoint could not reach the DB within 60 seconds; MySQL came up late or the password does not match.',
            'steps' => [
                'Plane applies “Restart” (it starts if the DB is ready now).',
                'If it recurs the Coolify DB_PASSWORD may differ from the one inside MySQL: look for “Access denied” in the mysql log.',
            ],
            'commands' => [
                'docker logs :container --tail 60',
            ],
        ],
        'app_redis_unreachable' => [
            'title' => 'Application could not reach Redis',
            'cause' => 'The Redis container was not ready or exited.',
            'steps' => [
                'Plane applies “Restart”. If it recurs read the redis container log.',
            ],
            'commands' => [
                'docker logs :container --tail 60',
            ],
        ],
        'migration_failed' => [
            'title' => 'Database migration failed',
            'cause' => 'The new version’s migration failed on this site’s data (SQLSTATE). Code or data related; redeploying does not help.',
            'steps' => [
                '“Roll back to last good” brings the site up immediately (pin).',
                'Send the SQLSTATE line to a developer; once the fix lands in the CMS, follow HEAD again.',
            ],
            'commands' => [
                'docker logs :container --tail 120 | grep -i -A5 SQLSTATE',
            ],
        ],
        'volume_permission' => [
            'title' => 'Volume permission error',
            'cause' => 'The container cannot write to its volume (ownership/permissions). Usually hand-copied data or a different UID.',
            'steps' => [
                'Fix the volume ownership on the server (999:999 for mysql, www-data for app storage), then “Redeploy”.',
            ],
            'commands' => [
                'docker logs :container --tail 40',
                'docker volume ls --filter name=:uuid',
            ],
        ],
        'build_failed' => [
            'title' => 'Image build failed',
            'cause' => 'A Dockerfile / npm / composer step failed. The cause is in the code; the same commit fails on other sites too.',
            'steps' => [
                '“Roll back to last good” keeps the site on the previous version.',
                'Send the first ERROR line of the build log to a developer. When the fix lands, “Follow HEAD”.',
            ],
            'commands' => [],
        ],
        'compose_domains_before_raw' => [
            'title' => 'Domain could not be bound: compose not loaded yet',
            'cause' => 'Coolify cannot write domains before it has read the compose file from git. An ordering issue, not a setting.',
            'steps' => [
                'Plane redeploys; once done it binds the domain automatically (“Bind domains”).',
            ],
            'commands' => [],
        ],
        'no_deployment_uuid' => [
            'title' => 'Coolify returned no deployment id',
            'cause' => 'The deploy request was accepted but Coolify gave no uuid; Plane could not follow it. The deploy may have run in Coolify.',
            'steps' => [
                'Plane syncs the deployment history from Coolify; the real state lands on this row.',
            ],
            'commands' => [],
        ],
        'coolify_timeout' => [
            'title' => 'Plane timed out waiting for the deploy result',
            'cause' => 'The build took long or the webhook never arrived. The deploy may have finished in Coolify.',
            'steps' => [
                'Plane syncs from Coolify; if the real result is “finished” the site becomes active on its own.',
            ],
            'commands' => [],
        ],
        'coolify_rate_limited' => [
            'title' => 'Coolify rate limit (429)',
            'cause' => 'Coolify throttled the API during a bulk operation; the status could not be read and the deploy may not have failed.',
            'steps' => [
                'Plane syncs. If this is frequent, run bulk deploys in smaller groups.',
            ],
            'commands' => [],
        ],
    ],
];
