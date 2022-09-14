<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Origin, X-Requested-With, Content-Type, Accept');
//エラーはjsonに出力する
//error_reporting(0);
//json読み込み
$setting = json_decode(mb_convert_encoding(file_get_contents("./setting.json"), 'UTF8', 'ASCII,JIS,UTF-8,EUC-JP,SJIS-WIN'), true);
$adjust = json_decode(mb_convert_encoding(file_get_contents("./adjust.json"), 'UTF8', 'ASCII,JIS,UTF-8,EUC-JP,SJIS-WIN'), true);
$replace = json_decode(mb_convert_encoding(file_get_contents("./replace.json"), 'UTF8', 'ASCII,JIS,UTF-8,EUC-JP,SJIS-WIN'), true);

//ステータス出力用json
$status_json = array();
$status_json["error"] = array();
$status_json["sql"] = array();
$status_json["status"] = array();

//MySQLの情報入力
$link = mysqli_connect($setting["db"]["host"], $setting["db"]["user"], $setting["db"]["password"], $setting["db"]["dbname"]);
if (!mysqli_connect($setting["db"]["host"], $setting["db"]["user"], $setting["db"]["password"], $setting["db"]["dbname"])) {
    $status_json["error"][] = "データベースに接続できません。";
    echo json_encode($status_json);
    die();
}

//親ページのpost_id
$parent_ids = $setting["parent_ids"];

$output_sql_col = "INSERT INTO `wp_posts` (`ID`, `post_author`, `post_date`, `post_date_gmt`, `post_content`, `post_title`, `post_excerpt`, `post_status`, `comment_status`, `ping_status`, `post_password`, `post_name`, `to_ping`, `pinged`, `post_modified`, `post_modified_gmt`, `post_content_filtered`, `post_parent`, `guid`, `menu_order`, `post_type`, `post_mime_type`, `comment_count`) VALUES ";
/**
 * 指定したpost_idを持つページと、その子ページをinsertするSQLを吐く
 * (指定したpost_idは最上位の親になる)
 */
if (!empty($parent_ids)) {
    foreach ($parent_ids as $parent_id) {
        $all_children_ids = array();
        all_children_ids_get($all_children_ids, [$parent_id], $link);
        $parent_sql = "
            SELECT *
            FROM wp_posts
            WHERE";
        foreach ($all_children_ids as $index => $parent_id) {
            $parent_sql .= " ID = " . $parent_id;
            if ($index !== array_key_last($all_children_ids)) {
                $parent_sql .= " OR";
            }
        }
        $parent_sql .= " AND post_status = 'publish' AND post_type = 'page'";

        if (!mysqli_query($link, $parent_sql)) { //SQLエラー
            $status_json["error"][] = mysqli_error($link);
        } else {
            $result_parent = mysqli_query($link, $parent_sql, MYSQLI_STORE_RESULT);
            $parent_array = $result_parent->fetch_all(MYSQLI_ASSOC);
            //$status_json["status"][post_id]["top_hierarchy"]
            $status_json["status"][$parent_array[0]["ID"]]["top_hierarchy"] = $parent_array[0]["post_title"];

            //整形
            list($parent_array, $status_json) = adjust_hierarchy($adjust, $parent_array, $status_json, $link);

            //文字置換
            list($parent_array, $status_json) = replace_post_content($replace, $parent_array, $status_json);

            //出力内容作成
            $output_sql = $output_sql_col . output_sql_make($parent_array, [$parent_id]);

            //SQLをファイルに出力する（ファイル名は親スラッグ）
            if (substr($output_sql, -1) == ",") {
                $output_sql = substr($output_sql, 0, -1);
            }
            $file_pass = "./sql/" . $parent_array[0]["post_name"] . ".sql";
            $output_status = file_put_contents($file_pass, $output_sql, LOCK_EX);
            if ($output_status !== false) {
                $status_json["sql"][] = $file_pass;
            }
        }
    }
}

/**
 * 指定したpost_idを持つページと、その子ページを除いた全部のpostをinsertするSQLを吐く
 */
$all_children_ids = array();
all_children_ids_get($all_children_ids, $parent_ids, $link);

$others_sql = "
SELECT *
FROM wp_posts
WHERE
    post_status = 'publish' 
    AND post_type = 'page'";
foreach ($all_children_ids as $parent_id) {
    $others_sql .= " AND ID != " . $parent_id;
};

$result_other = mysqli_query(
    $link,
    $others_sql,
    MYSQLI_STORE_RESULT
);
if (!mysqli_query($link, $others_sql)) { //SQLエラー
    $status_json["error"][] = mysqli_error($link);
} else {
    $other_array = $result_other->fetch_all(MYSQLI_ASSOC);

    if (!empty($parent_ids)) {
        $status_json["status"][$other_array[0]["ID"]]["top_hierarchy"] = "指定したid以外";
    } else {
        $status_json["status"][$other_array[0]["ID"]]["top_hierarchy"] = "全ての固定ページ";
    }

    //整形
    list($other_array, $status_json) = adjust_hierarchy($adjust, $other_array, $status_json, $link);

    //文字置換
    list($other_array, $status_json) = replace_post_content($replace, $other_array, $status_json);


    //出力内容作成
    $other_output_sql = $output_sql_col . output_sql_make($other_array);

    //SQLをファイルに出力する
    if (substr($other_output_sql, -1) == ",") {
        $other_output_sql = substr($other_output_sql, 0, -1);
    }
    if (!empty($parent_ids)) {
        $file_pass = "./sql/other-all.sql";
    } else {
        $file_pass = "./sql/all.sql";
    }

    $output_status = file_put_contents($file_pass, $other_output_sql, LOCK_EX);
    if ($output_status !== false) {
        $status_json["sql"][] = $file_pass;
    }
}

/**
 * 新しくDBを作成してそこに移動する
 */
$create_moves = array();
foreach ($adjust["create_move"] as $key => $value) {
    if ($key !== "_comment") {
        $create_moves[$key] = $value;
    }
}
if (!empty($create_moves)) {
    foreach ($create_moves as $key => $create_move) {
        $output_sql = $output_sql_col;
        foreach ($create_move as $value) {
            $all_children_ids = array();
            all_children_ids_get($all_children_ids, [$value], $link);
            $create_move_sql = "
            SELECT *
            FROM wp_posts
            WHERE";
            foreach ($all_children_ids as $index => $parent_id) {
                $create_move_sql .= " ID = " . $parent_id;
                if ($index !== array_key_last($all_children_ids)) {
                    $create_move_sql .= " OR";
                }
            }
            $create_move_sql .= " AND post_status = 'publish' AND post_type = 'page'";

            if (!mysqli_query($link, $create_move_sql)) { //SQLエラー
                $status_json["error"][] = mysqli_error($link);
            } else {
                $result_create_move = mysqli_query($link, $create_move_sql, MYSQLI_STORE_RESULT);
                $create_move_array = $result_create_move->fetch_all(MYSQLI_ASSOC);

                //json整形
                $status_json["status"][$key]["top_hierarchy"] = $key;
                foreach ($create_move_array as $value) {
                    $status_json = json_make_action($key, $value, $status_json, "新DBに追加");
                }

                //文字置換
                list($create_move_array, $status_json) = replace_post_content($replace, $create_move_array, $status_json);

                //出力内容作成
                $output_sql .= output_sql_make($create_move_array, [$value]);
            }
        }
        //SQLをファイルに出力する（ファイル名は親スラッグ）
        if (substr($output_sql, -1) == ",") {
            $output_sql = substr($output_sql, 0, -1);
        }
        $file_pass = "./sql/" . $key . ".sql";
        $output_status = file_put_contents($file_pass, $output_sql, LOCK_EX);
        if ($output_status !== false) {
            $status_json["sql"][] = $file_pass;
        }
    }
}


mysqli_close($link);

echo json_encode($status_json);


/**
 * 渡されたidと、そのidを親・祖先に持つ子孫のページ全てのidを再帰的に取得する
 * &$all_children_ids : 値を受け取る配列
 * $ids : 親ページのpost_id（配列）
 * $link : MySQLサーバーへの接続オブジェクト
 * return bool
 */
function all_children_ids_get(array &$all_children_ids, array $ids, $link)
{
    foreach ($ids as $id) {
        $all_children_ids[] = $id;
        if (function_exists('children_check') && children_check($id, $link)) {
            $sql = "
                SELECT ID
                FROM wp_posts
                WHERE
                    post_status = 'publish' 
                    AND post_type = 'page'
                    AND post_parent = " . $id;

            $result = mysqli_query($link, $sql, MYSQLI_STORE_RESULT);
            $values = $result->fetch_all(MYSQLI_ASSOC);

            foreach ($values as $value) {
                $this_id = array();
                $this_id[] = $value["ID"];
                all_children_ids_get($all_children_ids, $this_id, $link);
            }
        }
    }
    return true;
}

/**
 * 出力するSQLのINSERT内容作成
 * $array : fetch_allメソッドで出てきた二次元配列
 * $delete_parent : 親子関係なしのページにしたいpost_id
 */
function output_sql_make(array $array, array $delete_parent = [0])
{
    $output_sql_type_int = ["ID", "post_author", "post_parent", "menu_order", "comment_count"];
    $text = "";
    foreach ($array as $key => $array_s) {

        $text .= "(";
        foreach ($array_s as $key_s => $value) {
            if (in_array($key_s, $output_sql_type_int)) {
                if ($key_s == "post_parent" && in_array($value, $delete_parent)) {
                    $text .= 0;
                } else {
                    $text .= $value;
                }
            } else {
                $text .= "'" . addslashes($value) . "'";
            }
            if ($key_s !== array_key_last($array_s)) {
                $text .= ",";
            }
        }
        $text .= "),";
    }
    return $text;
}

/**
 * 渡したpost_idを親に持つページの存在チェック
 * $id : 親ページのpost_id
 * $link : MySQLサーバーへの接続オブジェクト
 */
function children_check(int $id, $link)
{
    $sql = "
    SELECT *
    FROM wp_posts
    WHERE
        post_status = 'publish' 
        AND post_type = 'page'
        AND post_parent = 
    " . $id . " LIMIT 1";
    $result = mysqli_query($link, $sql, MYSQLI_STORE_RESULT);
    if (empty($result->fetch_all(MYSQLI_ASSOC))) {
        return false;
    } else {
        return true;
    }
}

/**
 * json作成（actionの内容作成）
 * $grand_parent_id : 最上位の親ページのpost_id（int or string）交差型はPHP 8.1.0以降しか使えないのでやめておく
 * $target : 投稿１つの配列
 * $json : 整形したいjson
 * $action : actionの名前
 */
function json_make_action($grand_parent_id, array $target, $json, string $action)
{
    if (isset($json["status"][$grand_parent_id][$target["ID"]]["action"])) {
        if (!in_array($action, $json["status"][$grand_parent_id][$target["ID"]]["action"])) {
            $json["status"][$grand_parent_id][$target["ID"]]["action"][] = $action;
        }
    } else {
        $json["status"][$grand_parent_id][$target["ID"]]["post_title"] = $target["post_title"];
        $json["status"][$grand_parent_id][$target["ID"]]["action"][] = $action;
    }
    return $json;
}


/**
 * 他階層に移動するページや、削除するページの指定（adjust.json）に合わせて配列を整える
 * $adjust : adjust.jsonをdecodeした配列
 * $target  : 整えたい配列
 * $status_json : ステータス用json
 * $link : MySQLサーバーへの接続オブジェクト
 */
function adjust_hierarchy(array $adjust, array $target, $status_json, $link)
{
    //単純削除
    foreach ($target as $key => $values) {
        if (in_array($values["ID"], $adjust["delete"])) {
            $status_json = json_make_action($target[0]["ID"], $values, $status_json, "削除");
            unset($target[$key]);
        }
    }
    $target = array_values($target);

    //移動
    $move = array();
    foreach ($adjust["move"] as $key => $move_ids) {
        if ($key != "_comment" && $key != "0") {
            foreach ($move_ids as $move_id) {
                $move[] = $move_id;
            }
        }
    }
    foreach ($adjust["create_move"] as $key => $move_ids) {
        if ($key != "_comment") {
            foreach ($move_ids as $move_id) {
                $move[] = $move_id;
            }
        }
    }
    $move_all = array();
    all_children_ids_get($move_all, $move, $link); //移動したいpost_idの子も全部取得する
    //移動元のpostを削除する
    $move_array = $adjust["move"];
    foreach ($target as $key => $values) {
        if (isset($move_array["0"])) {
            if (in_array($values["ID"], $move_all) && !in_array($values["ID"], $move_array["0"])) {
                $status_json = json_make_action($target[0]["ID"], $values, $status_json, "移動");
                unset($target[$key]);
            }
        } else {
            if (in_array($values["ID"], $move_all)) {
                $status_json = json_make_action($target[0]["ID"], $values, $status_json, "移動");
                unset($target[$key]);
            }
        }
    }
    $target = array_values($target);

    //整えたい配列に移動先の親があるか確認、あれば移動元の子をDBから取得してきて配列に追加する
    foreach ($target as $values) { //整えたい配列をループ
        $result_array = array();
        $move_key = $values["ID"];
        $insert_ids = array();
        if (isset($move_array[$move_key])) { //普通に移動する。整えたい配列の子へ移動したいpost_idを$adjust["move"]から探す
            $insert_ids = $move_array[$move_key];
            $all_insert_ids = array();
            all_children_ids_get($all_insert_ids, $insert_ids, $link); //移動したいpost_idの子も全部一緒に移動する
            $sql = "
                SELECT *
                FROM wp_posts
                WHERE";
            foreach ($all_insert_ids as $index => $insert_id) {
                $sql .= " ID = " . $insert_id;
                if ($index !== array_key_last($all_insert_ids)) {
                    $sql .= " OR";
                }
            }
            $sql .= " AND post_status = 'publish' AND post_type = 'page'";

            $result = mysqli_query(
                $link,
                $sql,
                MYSQLI_STORE_RESULT
            );
            $result_array = $result->fetch_all(MYSQLI_ASSOC); //移動したいpostを全取得
            foreach ($result_array as $result_value) {
                if (in_array($result_value["ID"], $move_array[$move_key])) { //移動したいpost達にも親子関係がある。移動したいpost達の親だけ、その親を帰ることで階層を保つ
                    $result_value["post_parent"] = $move_key;
                }
                $status_json = json_make_action($target[0]["ID"], $values, $status_json, "追加");
                $status_json["status"][$target[0]["ID"]][$move_key]["add"][$result_value["ID"]] = $result_value["post_title"];
                $target[] = $result_value;
            }
        }
        if (isset($move_array["0"])) {
            if (in_array($move_key, $move_array["0"])) { //最上位に移動する。$adjust["move"][0]、つまり最上位に移動したい子ページかどうか調べる
                $values["post_parent"] = 0;
                $status_json = json_make_action($target[0]["ID"], $values, $status_json, "最上位移動");
                $status_json["status"][$target[0]["ID"]][$move_key]["add"][$move_key] = $values["post_title"];
                $target[] = $values;
            }
        }
    }

    return [$target, $status_json];
}

/**
 * 文字列置換設定（replace.json）に合わせて配列を整える
 * $replace : replace.jsonをdecodeした配列
 * $target  : 整えたい配列
 * $status_json : ステータス用json
 */
function replace_post_content(array $replace, array $target, $status_json)
{
    $return_array = array();
    $status = array();
    foreach ($target as $values) { //整えたい対象の配列をループ
        //単純置換
        foreach ($replace["simple"] as $before => $after) {
            $count = 0;
            if ($before != "_comment") {
                $values["post_content"] = str_replace($before, $after, $values["post_content"], $count);
                if ($count !== 0) {
                    $status_json = json_make_action($target[0]["ID"], $values, $status_json, "単純置換");
                    $status_json["status"][$target[0]["ID"]][$values["ID"]]["replace"][] = [htmlspecialchars($before), htmlspecialchars($after), $count];
                }
            }
        }

        //正規表現置換
        foreach ($replace["regex"] as $before => $after) {
            $count = 0;
            if ($before != "_comment") {
                $values["post_content"] = preg_replace($before, $after, $values["post_content"], -1, $count);
                if ($count !== 0) {
                    $status_json = json_make_action($target[0]["ID"], $values, $status_json, "正規表現置換");
                    $status_json["status"][$target[0]["ID"]][$values["ID"]]["regex"][] = [htmlspecialchars($before), htmlspecialchars($after), $count];
                }
            }
        }
        $return_array[] = $values;
    }
    return [$return_array, $status_json];
}