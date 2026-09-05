<?php
declare(strict_types=1);

$configPath=__DIR__.'/config.php';
// A deployed installation must never be reconfigured through the public installer.
if(is_file($configPath)){http_response_code(404);header('Content-Type: text/plain; charset=UTF-8');exit('Not Found');}

ini_set('session.use_strict_mode','1');ini_set('session.use_only_cookies','1');ini_set('session.cookie_httponly','1');ini_set('session.cookie_samesite','Lax');
$https=!empty($_SERVER['HTTPS'])&&strtolower((string)$_SERVER['HTTPS'])!=='off';
session_set_cookie_params(['lifetime'=>0,'path'=>'/','domain'=>'','secure'=>$https,'httponly'=>true,'samesite'=>'Lax']);
session_start();

$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){
    $dbHost=trim((string)($_POST['db_host']??'localhost'));$dbName=trim((string)($_POST['db_name']??''));$dbUser=trim((string)($_POST['db_user']??''));$dbPass=(string)($_POST['db_pass']??'');$name=trim((string)($_POST['name']??''));$email=mb_strtolower(trim((string)($_POST['email']??'')));$password=(string)($_POST['password']??'');
    if($dbName===''||$dbUser===''||$name===''||!filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($password)<8)$error='Заполните все поля. Пароль должен быть не короче 8 символов.';
    else{
        try{
            if(is_file($configPath))throw new RuntimeException('Kapouch уже установлен.');
            $pdo=new PDO("mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4",$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
            $schema=file_get_contents(__DIR__.'/database/schema.sql');if($schema===false)throw new RuntimeException('Не найден database/schema.sql');$pdo->exec($schema);
            require_once __DIR__.'/inc/updater.php';kapouch_apply_pending_migrations($pdo,false);
            $count=(int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
            if($count===0){$stmt=$pdo->prepare("INSERT INTO users(name,email,password_hash,role) VALUES(?,?,?,'owner')");$stmt->execute([$name,$email,password_hash($password,PASSWORD_DEFAULT)]);}
            $config="<?php\nreturn ".var_export(['app'=>['name'=>'Kapouch','timezone'=>'Asia/Irkutsk','currency'=>'₽'],'db'=>['host'=>$dbHost,'name'=>$dbName,'user'=>$dbUser,'pass'=>$dbPass,'charset'=>'utf8mb4'],'security'=>['encryption_key'=>bin2hex(random_bytes(32))]],true).";\n";
            $handle=@fopen($configPath,'x');if(!$handle)throw new RuntimeException('Не удалось безопасно создать config.php: файл уже существует или нет прав на запись.');
            try{if(fwrite($handle,$config)!==strlen($config))throw new RuntimeException('Не удалось полностью записать config.php.');fflush($handle);}finally{fclose($handle);}
            @chmod($configPath,0600);
            header('Location: login.php?installed=1');exit;
        }catch(Throwable $e){$error='Ошибка установки: '.$e->getMessage();}
    }
}
?>
<!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Установка Kapouch</title><link rel="stylesheet" href="assets/style.css?v=20260903-10"></head><body class="auth-body">
<div class="auth-card"><div style="font-size:22px;font-weight:800;margin-bottom:20px">Kapouch</div><h1>Установка</h1><p class="muted">Укажите базу MySQL, созданную в панели Beget, и данные владельца.</p>
<?php if($error):?><div class="alert danger"><?=htmlspecialchars($error,ENT_QUOTES,'UTF-8')?></div><?php endif;?>
<form method="post" class="stack"><label>Хост БД<input name="db_host" value="<?=htmlspecialchars($_POST['db_host']??'localhost',ENT_QUOTES,'UTF-8')?>" required></label><label>Имя БД<input name="db_name" required></label><label>Пользователь БД<input name="db_user" required></label><label>Пароль БД<input type="password" name="db_pass"></label><hr><label>Ваше имя<input name="name" required></label><label>Email<input type="email" name="email" required></label><label>Пароль владельца<input type="password" name="password" minlength="8" required></label><button class="btn primary" type="submit">Установить Kapouch</button></form></div></body></html>
