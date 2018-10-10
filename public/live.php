<?php
require_once("../lib/bootloader.php");

$id = s($_GET["id"]);
if (!$id) {
  http_response_code(421);
  exit("ERR:配信IDを入力してください。");
}

$live = getLive($id);
if (!$live) {
  http_response_code(404);
  exit("ERR:この配信は存在しません。");
}

$slot = getSlot($live["slot_id"]);
$my = getMe();
if (!$my && $live["privacy_mode"] == "3") {
  http_response_code(403);
  exit("ERR:この配信は非公開です。");
}
$liveUser = getUser($live["user_id"]);

$liveurl = (empty($_SERVER["HTTPS"]) ? "http://" : "https://") . $_SERVER["HTTP_HOST"] .($env["is_testing"] ?  u("live") . "?id=" : u("watch")) . $live["id"];

if (empty($_SESSION["watch_mode"])) {
  $_SESSION["watch_mode"] = preg_match('/(iPhone|iPad)/', $_SERVER['HTTP_USER_AGENT']) ? "hls" : "http-flv";
}
if (isset($_GET["watch_mode"])) $_SESSION["watch_mode"] = $_GET["watch_mode"] == 0 ? "http-flv" : ($_GET["watch_mode"] == 1 ? "dash" : ($_GET["watch_mode"] == 2 ? "hls" : null));
?>
<!doctype html>
<html lang="ja">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
  <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.1.3/css/bootstrap.min.css" integrity="sha384-MCw98/SFnGE8fJT3GXwEOngsV7Zt27NXFoaoApmYm81iuXoPkFOJwJ8ERdknLPMO"
        crossorigin="anonymous">
  <link rel="stylesheet" href="style.css">
  <title id="title-name"><?=$live["name"]?> - <?=$env["Title"]?></title>
  <style>
    #comments {
      overflow-y: scroll;
      overflow-x: hidden;
      height: 600px;
    }
    .hashtag {
      display: none;
    }
  </style>
</head>
<body>
<?php $navmode = "fluid"; include "../include/navbar.php"; ?>
<div class="container-fluid">
  <div class="row">
    <div class="col-md-9">
      <div class="embed-responsive embed-responsive-16by9" id="live">
        <iframe class="embed-responsive-item" src="<?=u("live_embed")?>?id=<?=$id?>&rtmp=<?=$slot["server"]?>" allowfullscreen id="iframe"></iframe>
      </div>
      <div class="dropdown" style="display: inline-block;">
        <button class="btn btn-secondary dropdown-toggle button-player" type="button" id="dropdownMenuButton" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
          モード: <span id="live-mode"><?=s($_SESSION["watch_mode"])?></span>
        </button>
        <div class="dropdown-menu" aria-labelledby="dropdownMenuButton">
          <a class="dropdown-item<?=($_SESSION["watch_mode"] === "http-flv" ? " active" : "")?>" href="?watch_mode=0">HTTP-FLV</a>
          <a class="dropdown-item<?=($_SESSION["watch_mode"] === "dash" ? " active" : "")?>" href="?watch_mode=1">MPEG-DASH</a>
          <a class="dropdown-item<?=($_SESSION["watch_mode"] === "hls" ? " active" : "")?>" href="?watch_mode=2">HLS</a>
        </div>
      </div>
      <button class="btn btn-primary btn-small button-player" onclick="reloadLive()">再読込</button>
      <a href="https://<?=$env["masto_login"]["domain"]?>/share?text=<?=urlencode("【視聴中】\n{$live["name"]} by @{$liveUser["acct"]}\n{$liveurl}\n\n#KnzkLive #knzklive_{$live["id"]}")?>" target="_blank" class="btn btn-info button-player">シェア</a>
      <span style="float: right">
          <span id="h"></span><span id="m"></span><span id="s"></span>
          <span id="count_open">
            視聴者数: <b id="count"><?=$live["viewers_count"]?></b> / <span class="max"><?=$live["viewers_max"]?></span>
          </span>
          <span id="count_end" class="invisible">
            総視聴者数(仮): <span class="max"><?=$live["viewers_max"]?></span>人 | 最大同時視聴者数: <span id="max_c"><?=$live["viewers_max_concurrent"]?></span>人
          </span>
        </span>
      <p></p>
      <h3 id="live-name"><?=$live["name"]?></h3>
      <img src="<?=$liveUser["misc"]["avatar"]?>" class="avatar_img_navbar rounded-circle"/> <?=$liveUser["name"]?>
      <p id="live-description"><?=nl2br($live["description"])?></p>
      <p id="err_live" class="text-warning"></p>

      <?php if ($my["id"] === $live["user_id"]) : ?>
        <p>
          <span class="text-warning">* これは自分の放送です。ミュートしないと音がループする可能性がありますのでご注意ください。</span>
        </p>
      <?php endif; ?>
    </div>
    <div class="col-md-3">
      <div>
        <?php if ($my) : ?>
          <div class="form-group">
            <textarea class="form-control" id="toot" rows="3" placeholder="コメント... (<?=$my["acct"]?>としてトゥート)" onkeyup="check_limit()"></textarea>
          </div>
          <div class="input-group">
            <button class="btn btn-primary" onclick="post_comment()">コメント</button>　<b id="limit"></b>
          </div>
        <?php else : ?>
          <p>
            <span class="text-warning">* コメントを投稿するにはログインしてください。<?=(!$liveUser["misc"]["live_toot"] ? "<br><br>{$env["masto_login"]["domain"]}のアカウントにフォローされているアカウントから#knzklive_{$id}をつけてトゥートしてもコメントする事ができます。" : "")?></span>
          </p>
        <?php endif; ?>
        <p class="invisible" id="err_comment">
          * コメントの読み込み中にエラーが発生しました。 <a href="javascript:loadComment()">再読込</a>
        </p>
        <hr>
      </div>
      <div id="comments"></div>
    </div>
  </div>
</div>
<script id="comment_tmpl" type="text/html">
  <div id="post_<%=id%>">
    <div class="row">
      <div class="col-2">
        <img src="<%=account['avatar']%>" class="avatar_img_navbar rounded-circle"/>
      </div>
      <div class="col-10">
        <b><%=account['display_name']%></b> <small>@<%=account['acct']%></small> <%=(me ? `<a href="#" onclick="delete_comment('${id}')">削除</a>` : "")%>
        <%=content%>
      </div>
    </div>
    <hr>
  </div>
</script>
<?php include "../include/footer.php"; ?>
<script src="js/tmpl.min.js"></script>
<script src="js/knzklive.js"></script>
<script>
  const hashtag_o = "knzklive_<?=$id?>";
  const hashtag = " #" + hashtag_o;
  const inst = "<?=$env["masto_login"]["domain"]?>";
  const token = "<?=$my ? s($_SESSION["token"]) : ""?>";
  var heartbeat, cm_ws, watch_data = {};
  var api_header = {'content-type': 'application/json'};
  if (token) api_header["Authorization"] = 'Bearer ' + token;

  const config = {
    "live_toot": <?=$liveUser["misc"]["live_toot"] ? "true" : "false"?>
  };

  function watch(first) {
    fetch('<?=u("api/client/watch")?>?id=<?=s($live["id"])?>', {
      method: 'GET',
      credentials: 'include',
    }).then(function(response) {
      if (response.ok) {
        return response.json();
      } else {
        throw response;
      }
    }).then(function(json) {
      const err = elemId("err_live");
      err.innerHTML = "";

      if (json["live_status"] === 1) err.innerHTML = "配信者からデータが送信されていません。";
      if (json["live_status"] === 0) {
        err.innerHTML = "この配信は終了しました。";
        elemId("count_open").className = "invisible";
        elemId("count_end").className = "";
        if (watch_data["live_status"] !== 0) document.getElementById('iframe').src = "<?=u("api/client/live_ended")?>";
      }
      if (json["live_status"] === 2 && watch_data["live_status"] !== 2) reloadLive();

      if (json["name"] !== watch_data["name"]) {
        elemId("live-name").innerHTML = json["name"];
        elemId("title-name").innerHTML = json["name"] + ` - <?=$env["Title"]?>`;
      }
      if (json["description"] !== watch_data["description"]) elemId("live-description").innerHTML = json["description"];

      if (json["viewers_count"] !== watch_data["viewers_count"]) elemId("count").innerHTML = json["viewers_count"];
      if (json["viewers_max"] !== watch_data["viewers_max"]) $(".max").html(json["viewers_max"]);
      if (json["viewers_max_concurrent"] !== watch_data["viewers_max_concurrent"]) elemId("max_c").innerHTML = json["viewers_max_concurrent"];
      watch_data = json;
      if (first) setInterval(date_disp, 1000);
    }).catch(function(error) {
      console.error(error);
      elemId("err_live").innerHTML = "データが読み込めません: ネットワークかサーバに問題が発生しています...";
    });
  }

  function date_disp() {
    /* thx https://www.tagindex.com/javascript/time/timer2.html */
    const now = watch_data["live_status"] === 0 ? new Date(watch_data["ended_at"]) : new Date();
    const datet = parseInt((now.getTime() - (new Date("<?=$live["created_at"]?>")).getTime()) / 1000);

    var hour = parseInt(datet / 3600);
    var min = parseInt((datet / 60) % 60);
    var sec = datet % 60;

    if (hour > 0) {
      if (hour < 10) hour = "0" + hour;
      elemId("h").innerHTML = hour + ":";
    }

    if (min < 10) min = "0" + min;
    elemId("m").innerHTML = min + ":";

    if (sec < 10) sec = "0" + sec;
    elemId("s").innerHTML = sec + " | ";
  }

  function reloadLive() {
    document.getElementById('iframe').src = document.getElementById('iframe').src;
  }

  function loadComment() {
    elemId("err_comment").className = "invisible";

    fetch('https://' + inst + '/api/v1/timelines/tag/' + hashtag_o, {
      headers: api_header,
      method: 'GET'
    })
    .then(function(response) {
      if (response.ok) {
        return response.json();
      } else {
        throw response;
      }
    })
    .then(function(json) {
      if (json) {
        var reshtml = "";
        var ws_url = 'wss://' + inst + '/api/v1/streaming/?stream=hashtag&tag=' + hashtag_o;
        if (token) ws_url += "&access_token=" + token;

        cm_ws = new WebSocket(ws_url);
        cm_ws.onopen = function() {
          heartbeat = setInterval(() => cm_ws.send("ping"), 5000);
          cm_ws.onmessage = function(message) {
            var ws_resdata = JSON.parse(message.data);
            var ws_reshtml = JSON.parse(ws_resdata.payload);

            if (ws_resdata.event === 'update') {
              if (ws_reshtml['id']) {
                if (!ws_reshtml['application'] && config["live_toot"]) {
                  console.log('COMMENT BLOCKED', ws_reshtml);
                  return;
                }
                if (config["live_toot"] && (
                  ws_reshtml['application']['name'] !== "KnzkLive" ||
                  ws_reshtml['application']['website'] !== "https://<?=$env["domain"]?>" ||
                  ws_reshtml['account']['acct'] !== ws_reshtml['account']['username']
                )) {
                  console.log('COMMENT BLOCKED', ws_reshtml);
                  return;
                }
                let acct = ws_reshtml['account']['acct'] !== ws_reshtml['account']['username'] ? ws_reshtml['account']['acct'] : ws_reshtml['account']['username'] + "@" + inst;
                ws_reshtml["me"] = "<?=$my["acct"]?>" === acct;
                ws_reshtml["account"]["display_name"] = escapeHTML(ws_reshtml["account"]["display_name"]);
                elemId("comments").innerHTML = tmpl("comment_tmpl", ws_reshtml) + elemId("comments").innerHTML;
              }
            } else if (ws_resdata.event === 'delete') {
              var del_toot = elemId('post_' + ws_resdata.payload);
              if (del_toot) del_toot.parentNode.removeChild(del_toot);
            }
          };

          cm_ws.onclose = function() {
            clearInterval(heartbeat);
            loadComment();
          };
        };
        cm_ws.onerror = function() {
          console.warn('err:ws');
        };

        var i = 0;
        while (json[i]) {
          if (!json[i]['application'] && config["live_toot"]) {
            console.log('COMMENT BLOCKED', json[i]);
          } else {
            if (config["live_toot"] && (
              json[i]['application']['name'] !== "KnzkLive" ||
              json[i]['application']['website'] !== "https://<?=$env["domain"]?>" ||
              json[i]['account']['acct'] !== json[i]['account']['username']
            )) {
              console.log('COMMENT BLOCKED', json[i]);
            } else {
              let acct = json[i]['account']['acct'] !== json[i]['account']['username'] ? json[i]['account']['acct'] : json[i]['account']['username'] + "@" + inst;
              json[i]["me"] = "<?=$my["acct"]?>" === acct;
              json[i]["account"]["display_name"] = escapeHTML(json[i]["account"]["display_name"]);
              reshtml += tmpl("comment_tmpl", json[i]);
            }
          }
          i++;
        }

        elemId("comments").innerHTML = reshtml;
      }
    })
    .catch(error => {
      console.log(error);
      elemId("err_comment").className = "text-danger";
    });
  }

  function post_comment() {
    fetch('https://' + inst + '/api/v1/statuses', {
      headers: api_header,
      method: 'POST',
      body: JSON.stringify({
        status: elemId("toot").value + hashtag,
        visibility: "public"
      })
    })
    .then(function(response) {
      if (response.ok) {
        return response.json();
      } else {
        throw response;
      }
    })
    .then(function(json) {
      if (json) {
        elemId("toot").value = "";
      }
    })
    .catch(error => {
      console.log(error);
      elemId("toot").value += "\n[投稿中にエラーが発生しました]";
    });
  }

  function delete_comment(_id) {
    fetch('https://' + inst + '/api/v1/statuses/' + _id, {
      headers: api_header,
      method: 'DELETE'
    })
    .then(function(response) {
      if (response.ok) {
        return response.json();
      } else {
        throw response;
      }
    })
    .then(function(json) {
    })
    .catch(error => {
      console.log(error);
      elemId("toot").value += "\n[実行中にエラーが発生しました]";
    });
  }

  function check_limit() {
    if (!token) return; //未ログイン
    const l = elemId("limit");
    const d = elemId("toot").value;
    l.innerText = 500 - hashtag.length - d.length;
  }

  window.onload = function () {
    check_limit();
    loadComment();
    watch(true);
    setInterval(watch, 5000);
    $('#toot').keydown(function (e){
      if (e.keyCode === 13 && e.ctrlKey) {
        post_comment()
      }
    });
  };
</script>
</body>
</html>