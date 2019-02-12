<?php
require_once("../lib/bootloader.php");
$my = getMe();
if (!$my) {
  http_response_code(403);
  exit("ERR:ログインしてください。");
}

if (!$my["broadcaster_id"]) {
  http_response_code(403);
  exit("ERR:あなたには配信権限がありません。");
}

if ($_POST) {
  $check = checkFileInfo($_POST["file"]);
  if (isset($check["error"])) exit("ERR: {$check["error"]}");
  $type = check_mime($_POST["file"], "sound");
  if (isset($type["error"])) exit("ERR: {$type["error"]}");

  $up = uploadS3(file_get_contents($_POST["file"]['tmp_name']), $_POST["file"]["size"], $type["mime"], $type["ext"], $my["id"]);
  if (isset($up["error"])) exit("ERR: {$up["error"]}");

  if (useFile($up["id"], "sound", $my["id"], true)) {

  }
}
?>
<!doctype html>
<html lang="ja">
<head>
  <?php include "../include/header.php"; ?>
  <title>ボイスアイテム管理 - <?=$env["Title"]?></title>
</head>
<body>
<?php include "../include/navbar.php"; ?>
<div class="container">
  <div class="box">
    <h4>ボイスアイテム管理</h4>
    「ボイスアイテム」は、配信のアイテム投下で配信者がカスタム追加できる音声です。<br>
    アイテムよりボイスを投下すると、リスナー全員にその音声が再生されます。<br>
    (* ミュート中は再生されません。)

    <form method="post" class="mt-4 mb-4 col-md-7">
      <div class="form-group">
        <label>ボイスファイル</label>
        <input type="file" name="file" required accept="audio/mp3, audio/wav">
        <small class="form-text text-muted">mp3, wavファイルがアップロードできます・<?=ini_get('upload_max_filesize')?>まで</small>
      </div>

      <div class="form-group">
        <label>ボイス名</label>
        <input class="form-control" type="text" name="word" required>
        <small class="form-text text-muted">20文字まで</small>
      </div>

      <div class="form-group">
        <label for="point">投下に必要なポイント</label>
        <div class="input-group">
          <input type="number" class="form-control" min="1" value="1" id="point" name="point" aria-describedby="kp" required>
          <div class="input-group-append">
            <span class="input-group-text" id="kp">KP</span>
          </div>
        </div>
      </div>
      <button class="btn btn-primary btn-block" type="submit">追加</button>
    </form>

    <div class="table-responsive">
      <table class="table">
        <thead>
        <tr>
          <th></th>
          <th>ボイス名</th>
          <th>ポイント</th>
          <th>コマンド</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($list as $item) : ?>
          <tr>
            <td><?=$item["acct"]?></td>
            <td><?=$item["created_at"]?></td>
            <td><?=$item["is_permanent"] === 1 ? "はい" : "いいえ"?></td>
            <td><?=$item["is_blocking_watch"] === 1 ? "はい" : "いいえ"?></td>
            <td><a href="#" onclick="remove('<?=$item["target_user_id"]?>', '<?=$item["acct"]?>', this);return false">削除</a></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php include "../include/footer.php"; ?>
<script>
function remove(id, name, obj = null) {
  if (confirm(`「${name}」を削除します。\nよろしいですか？`)) {
    fetch('<?=u("api/client/item")?>', {
      headers: {'content-type': 'application/x-www-form-urlencoded'},
      method: 'POST',
      credentials: 'include',
      body: buildQuery({
        csrf_token: `<?=$_SESSION['csrf_token']?>`,
        mode: 'remove',
        type: 'sounds',
        id: id,
      })
    }).then(function(response) {
      if (response.ok) {
        return response.json();
      } else {
        throw response;
      }
    }).then(function(json) {
      if (json["error"]) {
        alert(json["error"]);
        return null;
      }
      if (json["success"]) {
        $(obj).parent().parent().remove();
      } else {
        alert("エラーが発生しました。データベースに問題が発生している可能性があります。");
      }
    }).catch(function(error) {
      console.error(error);
      alert("内部エラーが発生しました");
    });
  }
}
</script>
</body>
</html>
