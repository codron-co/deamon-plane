<?php

return [
    'gate' => [
        'coolify' => 'Coolify auto-deploy',
        'ci' => 'CI gate',
        'auto_deploy_blocked' => ':name is on the CI gate: auto-deploy cannot be turned on. Switch its deploy gate to Coolify first.',
    ],
    'status' => [
        'canary' => 'canary',
        'fanout' => 'fanning out',
        'done' => 'done',
        'halted' => 'halted',
        'superseded' => 'superseded',
    ],
    'halt_reason' => [
        'manual' => 'Halted by an operator.',
        'timeout' => 'Canary timed out; not ready: :sites',
        'canary_deploy_failed' => 'Canary deploy request failed: :sites',
        'canary_failed' => 'Canary failed (build or health): :sites',
    ],
    'errors' => [
        'not_open' => 'This rollout is already stopped or finished.',
        'not_halted' => 'Only a halted rollout can be resumed.',
        'not_current' => ':sha is no longer the head of its branch; the newer push rolls out with its own CI run.',
    ],
];
