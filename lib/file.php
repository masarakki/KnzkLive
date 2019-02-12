<?php
function checkFileInfo($data) {
  $err = ["success" => false];
  if (!isset($data['error']) || !is_int($data['error'])) {
    $err["error"] = "パラメータが不正です";
    return $err;
  }

  $max_filesize = ini_get('upload_max_filesize');
  switch ($data['error']) {
    case UPLOAD_ERR_OK:
      break;
    case UPLOAD_ERR_INI_SIZE:
    case UPLOAD_ERR_FORM_SIZE:
      $err["error"] = "ファイルが大きすぎます, {$max_filesize}以内にしてください";
      break;
    case UPLOAD_ERR_PARTIAL:
      $err["error"] = "ファイルのアップロードが中断されました";
      break;
    case UPLOAD_ERR_NO_FILE:
      $err["error"] = "ファイルがありません";
      break;
    case UPLOAD_ERR_NO_TMP_DIR:
    case UPLOAD_ERR_CANT_WRITE:
    case UPLOAD_ERR_EXTENSION:
      $err["error"] = "サーバー側に問題がありファイルの書き込みに失敗しました, 管理者にお問い合わせください。";
      break;
    default:
      $err["error"] = "不明なエラー";
      break;
  }

  if (isset($err["error"])) return $err;

  return ["success" => true, "name" => $data["name"], "size" => $data["size"] ];
}

function check_mime($data, $allow_file_type) {
  $err = ["success" => false];
  if (!isset($data['error']) || !is_int($data['error'])) {
    $err["error"] = "パラメータが不正です";
    return $err;
  }

  $mime = mime_content_type($data['tmp_name']);
  $list = [
    "image/jpeg" => [
      "ext" => "jpg",
      "type" => "image"
    ],
    "image/png" => [
      "ext" => "png",
      "type" => "image"
    ],
  ];
  if (!isset($list[$mime]) || $list[$mime]["type"] !== $allow_file_type) $err["error"] = "ファイルタイプが不正です";
  if (isset($err["error"])) return $err;


  return ["success" => true, "mime" => $mime, "ext" => $list[$mime]["ext"]];
}

function initS3() {
  global $env;
  return new \Aws\S3\S3Client([
    'version' => 'latest',
    'endpoint' => $env["storage"]["endpoint"],
    'region' => $env["storage"]["region"],
    'credentials' => new Aws\Credentials\Credentials($env["storage"]["key"], $env["storage"]["secret"]),
    'http' => [
      'verify' => !$env["is_debug"]
    ]
  ]);
}

function uploadS3($blob, $size, $file_mime, $file_ext, $user_id) {
  global $env;
  $s3 = initS3();
  try {
    $id = generateHash();
    $path = $id . "." . $file_ext;
    $result = $s3->putObject([
      'Bucket' => $env["storage"]["bucket"],
      'Key'    => $path,
      'Body'   => $blob,
      'ContentType' => $file_mime,
      'ACL'    => 'public-read',
      'Metadata' => [
        'CRS-Uploaded-By' => $user_id
      ]
    ]);

    $mysqli = db_start();
    $stmt = $mysqli->prepare("INSERT INTO `media` (`file_path`, `content_type`, `file_size`, `created_by`, `ip`) VALUES (?, ?, ?, ?, ?);");
    $stmt->bind_param("sssss", $path, $file_mime, $size, $user_id, $_SERVER["REMOTE_ADDR"]);
    $stmt->execute();
    $sid = $stmt->insert_id;
    $err = $stmt->error;
    $stmt->close();
    $mysqli->close();

    if (!$err && $result["ObjectURL"]) {
      return ["success" => true, "path" => $path, "id" => $sid];
    } else {
      return ["success" => false, "error" => "データベースエラー"];
    }
  } catch (\Aws\S3\Exception\S3Exception $e) {
    if ($env["is_debug"]) {
      echo $e->getMessage() . PHP_EOL;
    }
    return ["success" => false, "error" => "アップロードエラー"];
  }
}

function deleteS3($id) {
  global $env;
  $file = getFile($id);
  if (!$file) return false;

  $s3 = initS3();
  try {
    $result = $s3->deleteObject([
      'Bucket' => $env["storage"]["bucket"],
      'Key'    => $file["file_path"],
    ]);
  } catch (\Aws\S3\Exception\S3Exception $e) {
    if ($env["is_debug"]) {
      echo $e->getMessage() . PHP_EOL;
    }
    return false;
  }
  $mysqli = db_start();
  $stmt = $mysqli->prepare("DELETE FROM `media` WHERE `id` = ?;");
  $stmt->bind_param("s", $file["id"]);
  $stmt->execute();
  $stmt->close();
  $mysqli->close();

  return true;
}

function getFile($id) {
  global $fileInfoCache;
  if (isset($fileInfoCache[$id])) return $fileInfoCache[$id];
  if (!$id) return false;

  $mysqli = db_start();
  $stmt = $mysqli->prepare("SELECT * FROM `media` WHERE file_id = ?;");
  $stmt->bind_param("s", $id);
  $stmt->execute();
  $row = db_fetch_all($stmt);
  $stmt->close();
  $mysqli->close();

  $fileInfoCache[$id] = isset($row[0]["id"]) ? $row[0] : false;
  return $fileInfoCache[$id];
}

function useFile($id, $type, $obj_id, $is_add = true) {
  $data = getFile($id);
  if (empty($data)) return false;

  $mysqli = db_start();
  if ($is_add) {
    $stmt = $mysqli->prepare("UPDATE `media` SET `obj_id` = ?, `obj_type` = ? WHERE id = ?;");
    $stmt->bind_param("sss", $obj_id, $type, $id);
  } else {
    $stmt = $mysqli->prepare("UPDATE `media` SET `obj_type` = null, `obj_id` = null WHERE id = ?;");
    $stmt->bind_param("s", $id);
  }
  $stmt->execute();
  $err = $stmt->error;
  $stmt->close();
  $mysqli->close();

  return !$err;
}
