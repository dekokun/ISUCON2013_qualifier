<?php

//ini_set( 'session.save_handler', 'memcached' );
//ini_set( 'session.save_path', 'localhost:11211' );

require_once 'limonade/lib/limonade.php';

function configure()
{
    option('base_uri', '');
    option('session', 'isucon_session');
 
    $db = null;
    try {
        $db = new PDO(
            'mysql:unix_socket=/var/lib/mysql/mysql.sock;dbname=isucon',
            "isucon",
            "",
            array(
                PDO::ATTR_PERSISTENT => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => 'SET CHARACTER SET `utf8`',
            )
        );
    } catch (PDOException $e) {
        halt("Connection faild: $e");
    }
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    option('db_conn', $db);
}

function uri_for($path) {
    $scheme = isset($_SERVER['HTTPS']) ? 'https' : 'http';
    $host = isset($_SERVER['HTTP_X_FORWARDED_HOST']) ?
        $_SERVER['HTTP_X_FORWARDED_HOST'] : $_SERVER['HTTP_HOST'];
    $base = $scheme . '://' . $host;
    return $base . $path;
}

function get($key) {
    // set returns already set value when value exists
    return set($key);
}

function before($route) {
    layout('layout.html.php');
    set('greeting', 'Hello');
    set('site_name', 'Isucon');

    $path = $_SERVER['QUERY_STRING'];
    $method = $route['method'];

    filter_session($route);

    if ($path != '/signin' || $method != 'POST') {
        // call except "POST /signin"
        filter_get_user($route);
    }

    if ($path == '/signout' || $path == '/mypage' || $path == '/memo') {
        filter_require_user($route);
    }

    if ($path == '/signout' || $path == '/memo') {
        filter_anti_csrf($route);
    }
}

function filter_session($route) {
    set('session_id', session_id());
    set('session', $_SESSION);
}

function filter_get_user($route) {
    $db = option('db_conn');

    $user_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : null;

    $stmt = $db->prepare('SELECT id, username FROM users WHERE id = :id');
    $stmt->bindValue(':id', $user_id);
    $stmt->execute();

    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    set('user', $user);

    if ($user) {
        header('Cache-Control: private');
    }
}

function filter_require_user($route) {
    if (!get('user')) {
        return redirect('/');
    }
}

function filter_anti_csrf($route) {
    $sid = $_POST["sid"];
    $token = $_SESSION["token"];

    if ($sid != $token) {
        return halt(400);
    }
}

function markdown($content) {
    $fh = tmpfile();
    $metadata = stream_get_meta_data($fh);
    $filename = $metadata['uri'];
    fwrite($fh, $content);
    $html = shell_exec("../bin/markdown " . $filename);
    fclose($fh);
    return $html;
}

dispatch_get('/', function() {
    $db = option('db_conn');

    $stmt = $db->prepare('SELECT count AS total FROM count_memos where id = 123');
    /* $stmt = $db->prepare('SELECT count(*) AS total FROM memos WHERE is_private=0'); */
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $total = $result["total"];

    $stmt = $db->prepare('SELECT memos.id, title, username, created_at FROM memos STRAIGHT_JOIN users on memos.user = users.id WHERE is_private=0 ORDER BY created_at DESC LIMIT 100');
    $stmt->execute();
    $memos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    set('memos', $memos);
    set('page', 0);
    set('total', $total);

    return html('index.html.php');
});

dispatch_get('/recent/:page', function(){
    $db = option('db_conn');

    $page = params('page');
    $stmt = $db->prepare('SELECT count AS total FROM count_memos where id = 123');
    /* $stmt = $db->prepare('SELECT count(*) AS total FROM memos WHERE is_private=0'); */
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $total = $result["total"];

    $stmt = $db->prepare("SELECT memos.id, title, username, created_at FROM memos STRAIGHT_JOIN users on memos.user = users.id WHERE is_private=0 ORDER BY created_at DESC LIMIT 100 OFFSET " . $page * 100);
    $stmt->execute();
    $memos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    set('memos', $memos);
    set('page', $page);
    set('total', $total);

    return html('index.html.php');

});

dispatch_get('/signin', function() {
    return html('signin.html.php');
});

dispatch_post('/signout', function() {
    if (!isset($_SESSION)) {
        session_start();
    }
    session_regenerate_id(TRUE);
    unset($_SESSION['user_id']);
    unset($_SESSION['token']);
    
    return redirect('/');
});

dispatch_post('/signin', function() {
    $db = option('db_conn');

    $username = $_POST['username'];
    $password = $_POST['password'];

    $stmt = $db->prepare('SELECT id, username, password, salt FROM users WHERE username = :username');
    $stmt->bindValue(':username', $username);
    $stmt->execute();
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user && $user['password'] == hash('sha256', $user['salt'] . $password, FALSE)) {
        session_regenerate_id(TRUE);
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['token'] = hash('sha256', rand(), FALSE);

        return redirect('/mypage');

    } else {
        return render('signin.html.php');
    }
});

dispatch_get('/mypage', function() {
    $db = option('db_conn');

    $user = get('user');

    $stmt = $db->prepare('SELECT id, title, is_private, created_at FROM memos WHERE user = :user ORDER BY created_at DESC');
    $stmt->bindValue(':user', $user['id']);
    $stmt->execute();
    $memos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    set('memos', $memos);
    return html('mypage.html.php');
});

dispatch_post('/memo', function() {
    $db = option('db_conn');

    $user = get('user');
    $content = $_POST["content"];
    $fragment = preg_split("/\r?\n/", $content);
    $title = $fragment[0];
    $html_content = markdown($_POST["content"]);
    $is_private = $_POST["is_private"] != 0 ? 1 : 0;

    $stmt = $db->prepare('INSERT INTO memos (user, title, content, html_content, is_private, created_at) VALUES (:user, :title, :content, :html_content, :is_private, now())');
    $stmt->bindValue(':user', $user['id']);
    $stmt->bindValue(':title', $title);
    $stmt->bindValue(':content', $content);
    $stmt->bindValue(':html_content', $html_content);
    $stmt->bindValue(':is_private', $is_private);
    $stmt->execute();

    $memo_id = $db->lastInsertId();
    
    if ($is_private == 0) {
      $stmt = $db->prepare('update count_memos set count = count + 1 where id = 123');
      $stmt->execute();    
    }

    return redirect('/memo/' . $memo_id);
});

dispatch_get('/memo/:id', function() {
    $db = option('db_conn');

    $user = get('user');
    $stmt = $db->prepare('SELECT id, user, html_content, is_private, created_at, updated_at FROM memos WHERE id = :id');
    $stmt->bindValue(':id', params('id'));
    $stmt->execute();
    $memo = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$memo) {
        return halt(404);
    }

    if ($memo['is_private'] != 0) {
        if (!$user || $user['id'] != $memo['user']) {
            return halt(404);
        }
    }

    $stmt = $db->prepare('SELECT username FROM users WHERE id = :id');
    $stmt->bindValue(':id', $memo['user']);
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $memo['username'] = $row['username'];

    
    if ($user && $user['id'] == $memo['user']) {
        $cond = "";
    }
    else {
        $cond = "AND is_private=0";
    }

    $stmt = $db->prepare("SELECT id, is_private, created_at FROM memos WHERE user = :user " . $cond . " ORDER BY created_at");
    $stmt->bindValue(':user', $memo['user']);
    $stmt->execute();
    $memos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $older = null;
    $newer = null;
    for ($i = 0; $i < count($memos); $i++) {
        if ($memos[$i]['id'] == $memo['id']) {
            if ($i > 0) {
                $older = $memos[$i - 1];
            }
            if ($i < count($memos) - 1) {
                $newer = $memos[$i + 1];
            }


        }
    }   

    set('memo', $memo);
    set('older', $older);
    set('newer', $newer);

    return html('memo.html.php');
});


# 
# function __xhprof_save() {
#     $data = xhprof_disable();
#     $XHPROF_ROOT = realpath(dirname(__FILE__) );
#     require_once $XHPROF_ROOT . "/xhprof_lib/utils/xhprof_lib.php";
#     require_once $XHPROF_ROOT . "/xhprof_lib/utils/xhprof_runs.php";
#     $runs = new XHProfRuns_Default('/tmp/xhprof');
#     $run_id = $runs->save_run($data, 'isucon_app');
# echo "<a href=\"http://ec2-176-32-67-9.ap-northeast-1.compute.amazonaws.com:5000/xhprof_html/index.php?run=$run_id&source=isucon_app\">xhprof Result</a>\n";
# }
# 
# xhprof_enable();
# register_shutdown_function('__xhprof_save');


run();



?>
