<?php
declare(strict_types=1);

$root=dirname(__DIR__);
$workflow=file_get_contents($root.'/.github/workflows/deploy-beget.yml');
$manifest=file_get_contents($root.'/scripts/beget_delta_manifest.py');
$docs=file_get_contents($root.'/docs/BEGET_DEPLOY.md');

$checks=[
    'deploy waits for successful quality push on main'=>str_contains($workflow,'workflows: ["Project quality"]')&&str_contains($workflow,"github.event.workflow_run.event == 'push'")&&str_contains($workflow,"github.event.workflow_run.conclusion == 'success'")&&str_contains($workflow,"github.event.workflow_run.head_branch == 'main'")&&str_contains($workflow,'github.event.workflow_run.head_repository.full_name == github.repository'),
    'deploy additionally waits for runtime regression'=>str_contains($workflow,'runtime-regression.yml/runs?head_sha=')&&str_contains($workflow,'Runtime regression passed'),
    'required Beget secrets are explicit'=>str_contains($workflow,'secrets.BEGET_HOST')&&str_contains($workflow,'secrets.BEGET_USER')&&str_contains($workflow,'secrets.BEGET_SSH_KEY')&&str_contains($workflow,'secrets.BEGET_PATH'),
    'missing secrets cause safe skip'=>str_contains($workflow,'configured=false')&&str_contains($workflow,'workflow is intentionally skipped'),
    'SSH requires key auth and host checking'=>str_contains($workflow,'BatchMode=yes')&&str_contains($workflow,'StrictHostKeyChecking=yes')&&!str_contains($workflow,'sshpass'),
    'rsync only receives explicit NUL-safe file manifest'=>str_contains($workflow,'--from0')&&str_contains($workflow,'--files-from="$RUNNER_TEMP/beget-upload.bin"')&&str_contains($workflow,'--delay-updates'),
    'full mirror delete is not used'=>!str_contains($workflow,'rsync --delete')&&!str_contains($workflow,' --delete '),
    'production deletions use generated safe manifest'=>str_contains($workflow,'beget-delete.bin')&&str_contains($workflow,"print('rm -f -- '+shlex.quote(root+'/'+rel))"),
    'successful deploy marker is tracked remotely'=>str_contains($workflow,'.kapouch-deployed-sha')&&str_contains($workflow,'Mark successful deployment checkpoint'),
    'health checks cover admin and customer domains'=>str_contains($workflow,"'https://kapouch.store/'")&&str_contains($workflow,"'https://app.kapouch.store/'"),
    'manifest protects runtime configuration'=>str_contains($manifest,"'config.php'")&&str_contains($manifest,"'.env'")&&str_contains($manifest,"'config.example.php'"),
    'manifest protects customer uploads and runtime logs'=>str_contains($manifest,"'customer/uploads/'")&&str_contains($manifest,"'storage/logs/'")&&str_contains($manifest,"'storage/cache/'")&&str_contains($manifest,"'storage/sessions/'"),
    'manifest excludes CI tests scripts and Evotor sources'=>str_contains($manifest,"'.github/'")&&str_contains($manifest,"'tests/'")&&str_contains($manifest,"'scripts/'")&&str_contains($manifest,"'evotor-app/'"),
    'manifest permits only known web application roots'=>str_contains($manifest,"'api/'")&&str_contains($manifest,"'assets/'")&&str_contains($manifest,"'cron/'")&&str_contains($manifest,"'customer/'")&&str_contains($manifest,"'database/'")&&str_contains($manifest,"'inc/'"),
    'protective htaccess files remain deployable'=>str_contains($manifest,"'customer/uploads/.htaccess'")&&str_contains($manifest,"'storage/logs/.htaccess'"),
    'renames become old delete plus new upload'=>str_contains($manifest,"if kind == 'R' and deployable(old)")&&str_contains($manifest,'uploads.add(new)'),
    'manifest supports an internal safety self test'=>str_contains($manifest,'def self_test()')&&str_contains($manifest,"'customer/uploads/avatar.jpg'")&&str_contains($manifest,"'../escape.php'"),
    'setup documentation explains first-run baseline'=>str_contains($docs,'.kapouch-deployed-sha')&&str_contains($docs,'base_sha')&&str_contains($docs,'перед первым включением автоматики'),
];

foreach($checks as $label=>$ok){
    if(!$ok){fwrite(STDERR,"Beget delta deploy contract failed: {$label}\n");exit(1);}
    echo "OK: {$label}\n";
}

echo "BEGET DELTA DEPLOY CONTRACT PASSED\n";
