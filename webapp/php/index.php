<?php

require 'vendor/autoload.php';

use Slim\Http\Request;
use Slim\Http\Response;

use Dflydev\FigCookies\FigResponseCookies;
use Dflydev\FigCookies\FigRequestCookies;
use Dflydev\FigCookies\Cookie;
use Dflydev\FigCookies\SetCookie;

date_default_timezone_set('Asia/Tokyo');

define("TWIG_TEMPLATE_FOLDER", realpath(__DIR__) . "/views");
define("AVATAR_MAX_SIZE", 1 * 1024 * 1024);

function getPDO($readOnly=false)
{
    static $pdo = null;
    static $pdo_read_only = null;

    if ($readOnly) {
      if (!is_null($pdo_read_only)) {
          return $pdo_read_only;
      }
      # Slave DB
      $host = '127.0.0.1';
      $port = '3306';
      $user = 'isucon';
      $password = 'isucon';
      $dsn = "mysql:host={$host};port={$port};dbname=isubata;charset=utf8mb4";
      $pdo_read_only = new PDO(
          $dsn,
          $user,
          $password,
          [
              PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
              PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
          ]
      );
      $pdo_read_only->query("SET SESSION sql_mode='TRADITIONAL,NO_AUTO_VALUE_ON_ZERO,ONLY_FULL_GROUP_BY'");
      return $pdo_read_only;
    } else {
      if (!is_null($pdo)) {
          return $pdo;
      }
      # Master DB
      $host = getenv('ISUBATA_DB_HOST') ?: 'localhost';
      $port = getenv('ISUBATA_DB_PORT') ?: '3306';
      $user = getenv('ISUBATA_DB_USER') ?: 'root';
      $password = getenv('ISUBATA_DB_PASSWORD') ?: '';
      $dsn = "mysql:host={$host};port={$port};dbname=isubata;charset=utf8mb4";
    }

    $pdo = new PDO(
        $dsn,
        $user,
        $password,
        [
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
        ]
    );
    $pdo->query("SET SESSION sql_mode='TRADITIONAL,NO_AUTO_VALUE_ON_ZERO,ONLY_FULL_GROUP_BY'");
    return $pdo;
}

$app = new \Slim\App();

$container = $app->getContainer();

$container['view'] = function ($container) {
    $view = new \Slim\Views\Twig(TWIG_TEMPLATE_FOLDER, []);
    $view->addExtension(
        new \Slim\Views\TwigExtension(
            $container['router'],
            $container['request']->getUri()
        )
    );
    return $view;
};

$app->get('/initialize', function (Request $request, Response $response) {
    $dbh = getPDO();
    $dbh->query("DELETE FROM user WHERE id > 1000");
    $dbh->query("DELETE FROM image WHERE id > 1001");
    $dbh->query("DELETE FROM channel WHERE id > 10");
    $dbh->query("DELETE FROM message WHERE id > 10000");
    $dbh->query("DELETE FROM haveread");
    $response->withStatus(204);
});

function db_get_user(PDO $dbh, $userId)
{
    $stmt = $dbh->prepare("SELECT id, name, display_name FROM user WHERE id = ?");
    $stmt->execute([$userId]);
    return $stmt->fetch();
}

function db_add_message($dbh, $channelId, $userId, $message)
{
    $stmt = $dbh->prepare("INSERT INTO message (channel_id, user_id, content, created_at) VALUES (?, ?, ?, NOW())");
    $stmt->execute([$channelId, $userId, $message]);
}

$loginRequired = function (Request $request, Response $response, $next) use ($container) {
    $userId = FigRequestCookies::get($request, 'user_id')->getValue();
    if (!$userId) {
        return $response->withRedirect('/login', 303);
    }

    $request = $request->withAttribute('user_id', $userId);
    $container['view']->offsetSet('user_id', $userId);

    $user = db_get_user(getPDO(), $userId);
    if (!$user) {
        $response = FigResponseCookies::remove($response, 'user_id');
        return $response->withRedirect('/login', 303);
    }

    // profileでidを使ってる
    $request = $request->withAttribute('user', $user);
    // base.twigでname, display_nameを使ってる
    $container['view']->offsetSet('user', $user);

    $response = $next($request, $response);
    return $response;
};

function random_string($length)
{
    $str = "";
    while ($length--) {
        $str .= str_shuffle("1234567890abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ")[0];
    }
    return $str;
}

function register($dbh, $userName, $password)
{
    $salt = random_string(20);
    $passDigest = sha1(utf8_encode($salt . $password));
    $stmt = $dbh->prepare(
        "INSERT INTO user (name, salt, password, display_name, avatar_icon, created_at) ".
        "VALUES (?, ?, ?, ?, 'default.png', NOW())"
    );
    $stmt->execute([$userName, $salt, $passDigest, $userName]);
    $stmt = $dbh->query("SELECT LAST_INSERT_ID() AS last_insert_id");
    return $stmt->fetch()['last_insert_id'];
}

$app->get('/', function (Request $request, Response $response) {
    if (FigRequestCookies::get($request, 'user_id')->getValue()) {
        return $response->withRedirect('/channel/1', 303);
    }
    return $this->view->render($response, 'index.twig', []);
});

function list_channels_for_side_bar(): array
{
    $stmt = getPDO()->query("SELECT id, name FROM channel ORDER BY id");
    return $stmt->fetchall();
}

function get_channel_list_info(int $focusedChannelId): array
{
    $stmt = getPDO()->query("SELECT id, name, description FROM channel ORDER BY id");
    $channels = $stmt->fetchall();
    $description = "";

    foreach ($channels as $channel) {
        if ((int)$channel['id'] === $focusedChannelId) {
            $description = $channel['description'];
            break;
        }
    }
    return [$channels, $description];
}

$app->get('/channel/{channel_id}', function (Request $request, Response $response) {
    $channelId = $request->getAttribute('channel_id');
    list($channels, $description) = get_channel_list_info($channelId);
    return $this->view->render(
        $response,
        'channel.twig',
        [
            'channels' => $channels,
            'channel_id' => $channelId,
            'description' => $description
        ]
    );
})->add($loginRequired);

$app->get('/register', function (Request $request, Response $response) {
    return $this->view->render($response, 'register.twig', []);
});

$app->post('/register', function (Request $request, Response $response) {
    $name     = $request->getParam('name');
    $password = $request->getParam('password');
    if (!$name || !$password) {
        return $response->withStatus(400);
    }
    try {
        $userId = register(getPDO(), $name, $password);
    } catch (PDOException $e) {
        if ($e->errorInfo[1] === 1062) {
            return $response->withStatus(409);
        }
        throw $e;
    }
    $response = FigResponseCookies::set($response, SetCookie::create('user_id', $userId));
    return $response->withRedirect('/', 303);
});

$app->get('/login', function (Request $request, Response $response) {
    return $this->view->render($response, 'login.twig', []);
});

$app->post('/login', function (Request $request, Response $response) {
    $name = $request->getParam('name');
    $password = $request->getParam('password');
    $stmt = getPDO()->prepare("SELECT id, salt, password FROM user WHERE name = ?");
    $stmt->execute([$name]);
    $user = $stmt->fetch();
    if (!$user || $user['password'] !== sha1(utf8_encode($user['salt'] . $password))) {
        return $response->withStatus(403);
    }
    $response = FigResponseCookies::set($response, SetCookie::create('user_id', $user['id']));
    return $response->withRedirect('/', 303);
});

$app->get('/logout', function (Request $request, Response $response) {
    $response = FigResponseCookies::set($response, SetCookie::create('user_id', '0'));
    return $response->withRedirect('/', 303);
});

$app->post('/message', function (Request $request, Response $response) {
    $userId = FigRequestCookies::get($request, 'user_id')->getValue();
    $user = db_get_user(getPDO(), $userId);
    $message = $request->getParam('message');
    $channelId = (int)$request->getParam('channel_id');
    if (!$user || !$channelId || !$message) {
        return $response->withStatus(403);
    }
    db_add_message(getPDO(), $channelId, $userId, $message);
    return $response->withStatus(204);
});

$app->get('/message', function (Request $request, Response $response) {
    $userId = FigRequestCookies::get($request, 'user_id')->getValue();
    if (!$userId) {
        return $response->withStatus(403);
    }

    $channelId = $request->getParam('channel_id');
    $lastMessageId = $request->getParam('last_message_id');
    $dbh = getPDO();
    $sql = <<< SQL
SELECT 
m.id AS id,
m.user_id AS user_id,
DATE_FORMAT(m.created_at, '%Y/%m/%d %H:%i:%S') AS `date`,
m.content AS content,
u.name AS user_name,
u.display_name AS user_display_name,
u.avatar_icon AS user_avatar_icon
FROM message AS m
JOIN user AS u ON u.id = m.user_id
WHERE m.id > ? AND m.channel_id = ?
ORDER BY m.id DESC LIMIT 100
SQL;


    $stmt = $dbh->prepare($sql);
    $stmt->execute([$lastMessageId, $channelId]);
    $rows = $stmt->fetchall();
    $res = array_map(function(array $r) {
        return [
            'id' => (int)$r['id'],
            'user' => [
                'name' => $r['user_name'],
                'display_name' => $r['user_display_name'],
                'avatar_icon' => $r['user_avatar_icon'],
            ],
            'date' => $r['date'],
            'content' => $r['content'],
        ];
    }, $rows);
    $res = array_reverse($res);
    $maxMessageId = 0;
    if (count($rows) > 0) {
        $maxMessageId = max($maxMessageId, $rows[0]['id']);
    }
    $stmt = $dbh->prepare(
        "INSERT INTO haveread (user_id, channel_id, message_id, updated_at, created_at) ".
        "VALUES (?, ?, ?, NOW(), NOW()) ".
        "ON DUPLICATE KEY UPDATE message_id = ?, updated_at = NOW()"
    );
    $stmt->execute([$userId, $channelId, $maxMessageId, $maxMessageId]);
    return $response->withJson($res);
});

$app->get('/fetch', function (Request $request, Response $response) {
    $userId = FigRequestCookies::get($request, 'user_id')->getValue();
    if (!$userId) {
        return $response->withStatus(403);
    }

    $dbh = getPDO();
    $stmt = $dbh->query('SELECT id FROM channel');
    $rows = $stmt->fetchall();
    $channelIds = [];
    foreach ($rows as $row) {
        $channelIds[] = (int)$row['id'];
    }

    $res = [];
    foreach ($channelIds as $channelId) {
        $stmt = $dbh->prepare(
            "SELECT * ".
            "FROM haveread ".
            "WHERE user_id = ? AND channel_id = ?"
        );
        $stmt->execute([$userId, $channelId]);
        $row = $stmt->fetch();
        if ($row) {
            $lastMessageId = $row['message_id'];
            $stmt = $dbh->prepare(
                "SELECT COUNT(1) as cnt ".
                "FROM message ".
                "WHERE channel_id = ? AND ? < id"
            );
            $stmt->execute([$channelId, $lastMessageId]);
        } else {
            $stmt = $dbh->prepare(
                "SELECT COUNT(1) as cnt ".
                "FROM message ".
                "WHERE channel_id = ?"
            );
            $stmt->execute([$channelId]);
        }
        $r = [];
        $r['channel_id'] = $channelId;
        $r['unread'] = (int)$stmt->fetch()['cnt'];
        $res[] = $r;
    }

    return $response->withJson($res);
});

$app->get('/history/{channel_id}', function (Request $request, Response $response) {
    $page = $request->getParam('page') ?? '1';
    $channelId = $request->getAttribute('channel_id');
    if (!is_numeric($page)) {
        return $response->withStatus(400);
    }
    $page = (int)$page;

    $dbh = getPDO();
    $stmt = $dbh->prepare("SELECT COUNT(1) as cnt FROM message WHERE channel_id = ?");
    $stmt->execute([$channelId]);
    $cnt = (int)($stmt->fetch()['cnt']);
    $pageSize = 20;
    $maxPage = ceil($cnt / $pageSize);
    if ($maxPage == 0) {
        $maxPage = 1;
    }

    if ($page < 1 || $maxPage < $page) {
        return $response->withStatus(400);
    }

    $offset = ($page - 1) * $pageSize;
    $stmt = $dbh->prepare(
        "SELECT * ".
        "FROM message ".
        "WHERE channel_id = ? ORDER BY id DESC LIMIT $pageSize OFFSET $offset"
    );
    $stmt->execute([$channelId]);

    $rows = $stmt->fetchall();
    $messages = [];
    foreach ($rows as $row) {
        $r = [];
        $r['id'] = (int)$row['id'];
        $stmt = $dbh->prepare("SELECT name, display_name, avatar_icon FROM user WHERE id = ?");
        $stmt->execute([$row['user_id']]);
        $r['user'] = $stmt->fetch();
        $r['date'] = str_replace('-', '/', $row['created_at']);
        $r['content'] = $row['content'];
        $messages[] = $r;
    }
    $messages = array_reverse($messages);

    $channels = list_channels_for_side_bar();
    return $this->view->render(
        $response,
        'history.twig',
        [
            'channels' => $channels,
            'channel_id' => $channelId,
            'messages' => $messages,
            'max_page' => $maxPage,
            'page' => $page
        ]
    );
})->add($loginRequired);

$app->get('/profile/{user_name}', function (Request $request, Response $response) {
    $userName = $request->getAttribute('user_name');
    $channels = list_channels_for_side_bar();

    $stmt = getPDO()->prepare("SELECT * FROM user WHERE name = ?");
    $stmt->execute([$userName]);
    $user = $stmt->fetch();
    if (!$user) {
        return $response->withStatus(404);
    }

    $selfProfile = $request->getAttribute('user')['id'] == $user['id'];
    return $this->view->render(
        $response,
        'profile.twig',
        [
            'user' => $user,
            'channels' => $channels,
            'self_profile' => $selfProfile
        ]
    );
})->add($loginRequired);

$app->get('/add_channel', function (Request $request, Response $response) {
    $channels = list_channels_for_side_bar();
    return $this->view->render(
        $response,
        'add_channel.twig',
        [
            'channels' => $channels,
        ]
    );
})->add($loginRequired);

$app->post('/add_channel', function (Request $request, Response $response) {
    $name = $request->getParam('name');
    $description = $request->getParam('description');
    if (!$name || !$description) {
        return $response->withStatus(400);
    }

    $dbh = getPDO();
    $stmt = $dbh->prepare(
        "INSERT INTO channel (name, description, updated_at, created_at) ".
        "VALUES (?, ?, NOW(), NOW())"
    );
    $stmt->execute([$name, $description]);
    $channelId = $dbh->lastInsertId();
    return $response->withRedirect("/channel/$channelId", 303);
})->add($loginRequired);

$app->post('/profile', function (Request $request, Response $response) {
    $userId = FigRequestCookies::get($request, 'user_id')->getValue();
    if (!$userId) {
        return $response->withStatus(403);
    }

    $pdo = getPDO();
    $user = db_get_user($pdo, $userId);
    if (!$user) {
        return $response->withStatus(403);
    }

    $displayName = $request->getParam('display_name');
    $avatarName = null;
    $avatarData = null;

    $uploadedFile = $request->getUploadedFiles()['avatar_icon'] ?? null;
    if ($uploadedFile && $uploadedFile->getError() === UPLOAD_ERR_OK) {
        $filename = $uploadedFile->getClientFilename();
        $ext = pathinfo($filename, PATHINFO_EXTENSION);
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif'])) {
            return $response->withStatus(400);
        }

        $tmpfile = tmpfile();
        $metaData = stream_get_meta_data($tmpfile);
        $filepath = $metaData['uri'];

        $uploadedFile->moveTo($filepath);
        if (AVATAR_MAX_SIZE < filesize($filepath)) {
            return $response->withStatus(400);
        }
        $avatarData = file_get_contents($filepath);
        $avatarName = sha1($avatarData) . '.' . $ext;
    }

    if ($avatarName && $avatarData) {
        $stmt = $pdo->prepare("INSERT INTO image (name, data) VALUES (?, ?)");
        $stmt->bindParam(1, $avatarName);
        $stmt->bindParam(2, $avatarData, PDO::PARAM_LOB);
        $stmt->execute();
        $stmt = $pdo->prepare("UPDATE user SET avatar_icon = ? WHERE id = ?");
        $stmt->execute([$avatarName, $userId]);
    }

    if ($displayName) {
        $stmt = $pdo->prepare("UPDATE user SET display_name = ? WHERE id = ?");
        $stmt->execute([$displayName, $userId]);
    }

    return $response->withRedirect('/', 303);
})->add($loginRequired);

function ext2mime($ext)
{
    switch ($ext) {
        case 'jpg':
        case 'jpeg':
            return 'image/jpeg';
        case 'png':
            return 'image/png';
        case 'gif':
            return 'image/gif';
        default:
            return '';
    }
}

$app->get('/icons/{filename}', function (Request $request, Response $response) {
    if ($request->hasHeader('If-Modified-Since')) {
	return $response->withStatus(304);
    }
    
    $filename = $request->getAttribute('filename');
    $stmt = getPDO()->prepare("SELECT * FROM image WHERE name = ?");
    $stmt->execute([$filename]);
    $row = $stmt->fetch();

    if (!$row) {
      $stmt = getPDO()->prepare("SELECT * FROM image WHERE name = ?");
      $stmt->execute([$filename]);
      $row = $stmt->fetch();
    }

    $ext = pathinfo($filename, PATHINFO_EXTENSION);
    $mime = ext2mime($ext);

    if ($row && $mime) {
        $response->write($row['data']);
        return $response->withHeader('Content-type', $mime);
    }
    return $response->withStatus(404);
});

$app->run();
