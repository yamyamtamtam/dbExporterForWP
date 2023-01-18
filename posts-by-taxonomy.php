<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Origin, X-Requested-With, Content-Type, Accept');
//json読み込み
$setting = json_decode(mb_convert_encoding(file_get_contents("./setting-posts-by-taxonomy.json"), 'UTF8', 'ASCII,JIS,UTF-8,EUC-JP,SJIS-WIN'), true);
$replace = json_decode(mb_convert_encoding(file_get_contents("./replace.json"), 'UTF8', 'ASCII,JIS,UTF-8,EUC-JP,SJIS-WIN'), true);

//ステータス出力用json
$status_json = array();
$status_json["error"] = array();
$status_json["setting"] = array();
$status_json["status"] = array();

$install_path = $setting["paths"]["install_path"];
$old_file_path = $setting["paths"]["old_file_path"];
$new_file_path = $setting["paths"]["new_file_path"];

$status_json["setting"][] = [
    "old_file_path" => $setting["paths"]["old_file_path"],
    "new_file_path" => $setting["paths"]["new_file_path"],
    "convert_taxonomy" => $setting["convert_taxonomy"],
    "postmeta_keys" => $setting["postmeta_keys"],
    "cat_to_db" => $setting["cat_to_db"]
];

//変換するタクソノミー
$convert_taxonomy = $setting["convert_taxonomy"];

//抽出するpostametaテーブルのpostテーブルのmeta_key。"_thumbnail_id"はアイキャッチ画像
$postmeta_keys = explode(',', $setting["postmeta_keys"]);

//取得先DBの接続情報
$db = $setting["db"];

//カテゴリとDBの一覧
$insert_db_and_term = $setting["cat_to_db"];

$exist_post_ids = array();

foreach($insert_db_and_term as $insert_db => $insert_terms_string){
    /*
    echo '<pre><h1>このループで除外したID（参照先DBのもの）</h1><br>';
    var_dump($exist_post_ids);
    echo '</pre><br><br><br>';
    */
    $insert_terms = explode(',', $insert_terms_string);
    array_push(
        $exist_post_ids,
        exec_db_replace($db, $insert_db, $insert_terms, $install_path, $old_file_path, $new_file_path, $convert_taxonomy, $postmeta_keys)
    );
}

/**DB変換の実行 */
function exec_db_replace($db, string $insert_db, array $insert_terms, string $install_path, string $old_file_path, string $new_file_path, array $convert_taxonomy, array $postmeta_keys){
    global $status_json;
    //MySQLの情報入力
    $link = mysqli_connect($db["host"], $db["user"], $db["password"], $db["dbname"]);
    if (!mysqli_connect($db["host"], $db["user"], $db["password"], $db["dbname"])) {
        $status_json["error"][] = "データベースに接続できません。";
        die();
    }

    //wp_postsテーブルの必要なもの
    global $replace;
    $posts = get_posts_by_terms($convert_taxonomy["before"], $insert_terms, $link);
    $posts = replace_post_content($replace, $posts, $status_json["status"]);
    $posts = $posts[0];

    //前のループで挿入した新着情報は次以降のループでは入れない
    global $exist_post_ids;
    for($i = 0; $i < count($posts); $i++){
        if(in_array($posts[$i]["ID"],$exist_post_ids)){
            unset($posts[$i]);
        }elseif(isset($posts[$i]["ID"])){
            $exist_post_ids[] = $posts[$i]["ID"];
            echo '<pre><p>「' . $posts[$i]["post_title"] . '」 を挿入</p></pre><br>';
        }
    }

    $post_ids = array();
    foreach($posts as $post){
        array_push($post_ids,$post["ID"]);
    }

    //wp_postmetaテーブルの必要なもの
    //$postmeta_keys = ["approver_user_id", "caption1", "caption2", "caption3", "_caption1", "_caption2", "_caption3", "img1", "img2", "img3", "_img1", "_img2", "_img3", "_thumbnail_id"];
    $postmetas = get_postsmeta_by_ids($post_ids, $postmeta_keys, $link);

    //wp_termsテーブルの必要なもの
    $terms = get_terms_by_ids($post_ids, $link);

    //wp_term_relationshipsテーブルの必要なもの
    $term_relationships = get_term_relationships_by_ids($post_ids, $link);

    //wp_term_taxonomyテーブルの必要なもの
    $term_taxonomies = get_term_taxonomy_by_ids($post_ids, $convert_taxonomy, $link);

    //関連する画像やファイルを取得
    $file_keys = ["img1", "img2", "img3", "_thumbnail_id"];
    $files = get_files_by_ids($post_ids, $file_keys, $link);

    mysqli_close($link);


    $link = mysqli_connect($db["host"], $db["user"], $db["password"], $insert_db);
    if (!mysqli_connect($db["host"], $db["user"], $db["password"], $insert_db)) {
        $status_json["error"][] = "データベースに接続できません。";
        die();
    }
    //取得先DBのIDと挿入DBのAUTO INCREMENTの対照表を作る
    $replace_post_ids = array();
    foreach($posts as $post){
        //wp_postsに1行挿入して、AUTO INCREMENTの値を知る
        $increment_id = insert_posts($post, $install_path, $link);
        if($increment_id){
            $replace_post_ids[] =[
                "before" => $post["ID"],
                "after" => $increment_id[0]["LAST_INSERT_ID()"]
            ];
        }
    }

    //取得先DBのIDと挿入DBのAUTO INCREMENTの対照表を作る($replace_term_ids)、取得先DBと挿入後DBで名称の共通するタームの一覧を作る（$current_exist_terms）
    $unset_result = unset_term_name($terms, $link, $convert_taxonomy); //整形
    $terms = $unset_result["terms_data"];
    $current_exist_terms = $unset_result["current_exist_term_ids"];
    $replace_term_ids = array();
    foreach($terms as $term){
        $increment_terms = insert_terms($term, $link, $convert_taxonomy);
        if($increment_terms){
            $replace_term_ids[] =[
                "before" => $term["term_id"],
                "after" => $increment_terms[0]["LAST_INSERT_ID()"]
            ];
        }
    }

    //取得先DBのIDと挿入DBのAUTO INCREMENTの対照表を作る
    $duplication = array();
    $replace_term_taxonomy_ids = array();
    foreach($term_taxonomies as $term_taxonomy){
        $increment_term_taxonomy = insert_term_taxonomy($term_taxonomy, $term_relationships, $replace_term_ids, $current_exist_terms, $link, $convert_taxonomy);
        if(!in_array($term_taxonomy["term_id"], $duplication, true) && $increment_term_taxonomy){
            $replace_term_taxonomy_ids[] =[
                "before" => $term_taxonomy["term_id"],
                "after" => $increment_term_taxonomy
            ];
            array_push($duplication,$term_taxonomy["term_id"]);
        }
    }

    foreach($term_relationships as $term_relationship){
        insert_term_relationship($term_relationship, $replace_post_ids, $replace_term_taxonomy_ids, $current_exist_terms, $link);
    }

    //取得先DBのIDと挿入DBのAUTO INCREMENTの対照表を作る
    $replace_file_ids = array();
    foreach($files as $file){
        //wp_postsに1行挿入して、AUTO INCREMENTの値を知る
        $increment_file = insert_file($file, $old_file_path, $new_file_path, $replace_post_ids, $link);
        if($increment_file){
            $replace_file_ids[] =[
                "before" => $file["ID"],
                "after" => $increment_file[0]["LAST_INSERT_ID()"]
            ];
        }
    }

    foreach($postmetas as $postmeta){
        insert_postmeta($postmeta, $replace_post_ids, $replace_file_ids, $link);
    }
    mysqli_close($link);
}
echo json_encode($status_json);

/**
 * wp_postsへのInsert
 * $posts_data : postのデータ配列
 * $link : MySQLサーバーへの接続オブジェクト
 */
function insert_posts(array $post_data, string $install_path, $link){
    global $status_json;
    $sql = "INSERT INTO wp_posts (post_author,post_date,post_date_gmt,post_content,post_title,post_excerpt,post_status,comment_status,ping_status,post_password,post_name,to_ping,pinged,post_modified,post_modified_gmt,post_content_filtered,post_parent,guid,menu_order,post_type,post_mime_type,comment_count) VALUES (";
    $sql .= "'" . $post_data["post_author"] . "','" . $post_data["post_date"] . "','" . $post_data["post_date_gmt"] . "','" . $post_data["post_content"] . "','" . $post_data["post_title"] . "','" . $post_data["post_excerpt"] . "','" . $post_data["post_status"] . "','" . $post_data["comment_status"] . "','" . $post_data["ping_status"] . "','" . $post_data["post_password"] . "','" . $post_data["post_name"] . "','" . $post_data["to_ping"] . "','" . $post_data["pinged"] . "','" . $post_data["post_modified"] . "','" . $post_data["post_modified_gmt"] . "','" . $post_data["post_content_filtered"] . "','" . $post_data["post_parent"] . "','" . $post_data["guid"] . "','" . $post_data["menu_order"] . "','" . $post_data["post_type"] . "','" . $post_data["post_mime_type"] . "','" . $post_data["comment_count"] . "')";
    $result = mysqli_query(
        $link,
        $sql,
        MYSQLI_STORE_RESULT
    );
    if($result){
        $get_autoincriment_id = mysqli_query(
            $link,
            'SELECT LAST_INSERT_ID();',
            MYSQLI_STORE_RESULT
        );
        $autoincriment_id = $get_autoincriment_id->fetch_all(MYSQLI_ASSOC); 
        $sql = "UPDATE wp_posts SET guid = '" . $install_path . "?p=" . $autoincriment_id[0]['LAST_INSERT_ID()'] . "' WHERE ID = " . $autoincriment_id[0]['LAST_INSERT_ID()'];
        $result = mysqli_query(
            $link,
            $sql,
            MYSQLI_STORE_RESULT
        );
    
        return $autoincriment_id; 
    }else{
        $status_json["error"][] = "wp_postの情報を挿入できませんでした";
        return false;
    }
}

/**
 * wp_termsへのInsert前にデータ移行後DBに同名のタームがないか調べ、同名のタームがあればその列を除いた配列を返す
 * $terms_data : termsのデータ配列（全て）
 * $link : MySQLサーバーへの接続オブジェクト
 * $convert_taxonomy : タクソノミーの変換
 */
function unset_term_name(array $terms_data, $link, array $convert_taxonomy = []){
    global $status_json;
    //同名のタームがないか確認する
    $sql = "SELECT wp_terms.term_id,wp_terms.name FROM wp_terms INNER JOIN wp_term_taxonomy ON wp_terms.term_id = wp_term_taxonomy.term_id";
    if(!empty($convert_taxonomy)){
        $sql .= " WHERE wp_term_taxonomy.taxonomy = '" . $convert_taxonomy["after"] . "'";
    }
    $current = mysqli_query(
        $link,
        $sql,
        MYSQLI_STORE_RESULT
    );
    $current_terms = $current->fetch_all(MYSQLI_ASSOC);
    $exist = array();
    for($i = 0; $i < count($terms_data); $i++){ //移設前のDBから持ってきたタームの情報一覧
        foreach($current_terms as $current_term){ //移設後のDBにあるタームの一覧
            if(isset($terms_data[$i])){
                if($terms_data[$i]["name"] == $current_term["name"]){
                    unset($terms_data[$i]);
                    $exist[] = [
                        "name" => $current_term["name"],
                        "term_id" => $current_term["term_id"]
                    ];
                }
            }
        }
    }
    return ["terms_data" => $terms_data, "current_exist_term_ids" => $exist];
}

/**
 * wp_termへのInsert
 * $terms_data : termsのデータ配列
 * $link : MySQLサーバーへの接続オブジェクト
 * $convert_taxonomy : タクソノミーの変換
 */
function insert_terms(array $term_data, $link, array $convert_taxonomy = []){
    global $status_json;
    $sql = "INSERT INTO wp_terms (name,slug,term_group) VALUES (";
    $sql .= "'" . $term_data["name"] . "','" . $term_data["slug"] . "','" . $term_data["term_group"] . "')";
    $result = mysqli_query(
        $link,
        $sql,
        MYSQLI_STORE_RESULT
    );
    if($result){
        $get_autoincriment_ids = mysqli_query(
            $link,
            'SELECT LAST_INSERT_ID();',
            MYSQLI_STORE_RESULT
        );
        return $get_autoincriment_ids->fetch_all(MYSQLI_ASSOC);
    }else{
        $status_json["error"][] = "wp_termsの情報が挿入できませんでした";
        return false;
    }
}

/**
 * wp_term_taxonomyへのInsert
 * $term_taxonomy_data : term_taxonomyのデータ配列
 * $term_relationships_datas : term_relationships_dataのデータ配列（全て）
 * $replace_term_ids : 移行先DBにすでに名前のある、取得先DBのterm_idのリスト
 * $current_exist_term_id : 移行先DBにすでに名前のある、取得先DBのterm_idのリスト
 * $link : MySQLサーバーへの接続オブジェクト
 * $convert_taxonomy : タクソノミーの変換
 */
function insert_term_taxonomy(array $term_taxonomy_data, array $term_relationships_datas, array $replace_term_ids, array $current_exist_terms, $link, array $convert_taxonomy = []){
    global $status_json;
    $insert = false; //取得先DBと挿入後DBに同じ名前のタームがある場合は挿入しない。この場合は、対照表の配列に入ってこない
    foreach($replace_term_ids as $replace_term_id){
        if($replace_term_id["before"] == $term_taxonomy_data["term_id"]){
            $term_taxonomy_data["term_id"] = $replace_term_id["after"];
            $insert = true;
        }
    }
    if($insert){
        $count = 0;
        foreach($term_relationships_datas as $term_relationships_data){
            if($term_relationships_data["term_taxonomy_id"] == $term_taxonomy_data["term_taxonomy_id"]){
                $count++;
            }
        }
        $sql = "INSERT INTO wp_term_taxonomy (term_id,taxonomy,description,parent,count) VALUES (";
        $sql .= "'" . $term_taxonomy_data["term_id"] . "','";
        if($convert_taxonomy["before"] == $term_taxonomy_data["taxonomy"]){
            $term_taxonomy_data["taxonomy"] = $convert_taxonomy["after"];
        }
        $sql .= $term_taxonomy_data["taxonomy"] . "','";
        $sql .= $term_taxonomy_data["description"] . "','";
        if($convert_taxonomy["after"] == "post_tag"){
            $term_taxonomy_data["parent"] = 0;
        }
        $sql .= $term_taxonomy_data["parent"] . "','";
        $sql .= $count . "')";
        $result = mysqli_query(
            $link,
            $sql,
            MYSQLI_STORE_RESULT
        );
        if($result){
            $get_autoincriment_ids = mysqli_query(
                $link,
                'SELECT LAST_INSERT_ID();',
                MYSQLI_STORE_RESULT
            );
            $autoincriment_id = $get_autoincriment_ids->fetch_all(MYSQLI_ASSOC);
            return $autoincriment_id[0]["LAST_INSERT_ID()"];
        }else{
            $status_json["error"][] = "wp_term_taxonomyの情報が挿入できませんでした";
            return false;
        }
    }else{ //今のDBにあるタグの場合はUPDATEしなければならない
        $count = 0;
        $insert_id = '';
        foreach($term_relationships_datas as $term_relationships_data){
            foreach($current_exist_terms as $current_exist_term)
            if($term_relationships_data["name"] == $current_exist_term["name"]){
                $count++;
                $insert_id = $current_exist_term["term_id"];
            }
        }
        if($insert_id != ''){
            $sql = "UPDATE wp_term_taxonomy SET count = " . $count . " WHERE term_id = " . $insert_id;
            $result = mysqli_query(
                $link,
                $sql,
                MYSQLI_STORE_RESULT
            );
            if($result){
                $sql = "SELECT term_taxonomy_id FROM wp_term_taxonomy WHERE term_id = " . $insert_id;
                $result = mysqli_query(
                    $link,
                    $sql,
                    MYSQLI_STORE_RESULT
                );
                $term_taxonomy_id = $result->fetch_all(MYSQLI_ASSOC);
                return $term_taxonomy_id[0]["term_taxonomy_id"];
            }else{
                $status_json["error"][] = "wp_term_taxonomyの情報が挿入できませんでした";
                return false;
            }    
        }
    }
}

/**
 * wp_term_relationshipへのInsert
 * $term_relationships : wp_term_relationshipsのデータ配列
 * $replace_post_ids : 取得先DBと移行先DBのpost_id対照表
 * $replace_term_taxonomy_ids : 取得先DBと移行先DBのterm_taxonomy_id対照表
 * $current_exist_term_id : 移行先DBにすでに名前のある、取得先DBのterm_idのリスト
 * $link : MySQLサーバーへの接続オブジェクト
 */
function insert_term_relationship(array $term_relationships_data, array $replace_post_ids, array $replace_term_taxonomy_ids, array $current_exist_terms, $link){
    global $status_json;
    $sql = "INSERT INTO wp_term_relationships (object_id,term_taxonomy_id,term_order) VALUES (";
    foreach($replace_post_ids as $replace_post_id){
        if($replace_post_id["before"] == $term_relationships_data["object_id"]){
            $sql .= $replace_post_id["after"] . ",";      
        }
    }
    $exist_replace_term_taxonomy_id = false;
    foreach($replace_term_taxonomy_ids as $replace_term_taxonomy_id){
        if($replace_term_taxonomy_id["before"] == $term_relationships_data["term_taxonomy_id"]){
            $sql .= $replace_term_taxonomy_id["after"] . ",";
            $exist_replace_term_taxonomy_id = true;
        }
    }
        
    if(!$exist_replace_term_taxonomy_id){
        foreach($current_exist_terms as $current_exist_term){
            if($current_exist_term["name"] == $term_relationships_data["name"]){
                $sql .= $current_exist_term["term_id"] . ",";
            }
        }
    }
    
    $sql .= $term_relationships_data["term_order"] . ")";
    
    $result = mysqli_query(
        $link,
        $sql,
        MYSQLI_STORE_RESULT
    );
    if(!$result){
        $status_json["error"][] = "wp_term_relationshipの情報が挿入できませんでした";
        return false;
    }
}

/**
 * wp_postsへのInsert（画像やファイル）
 * $file_data : postのデータ配列
 * $old_file_path : 取得先DBのファイルパス
 * $new_file_path : 移行先DBのファイルパス
 * $replace_post_ids : 取得先DBと移行先DBのpost_id対照表
 * $link : MySQLサーバーへの接続オブジェクト
 */
function insert_file(array $file_data, string $old_file_path, string $new_file_path, array $replace_post_ids, $link){
    global $status_json;
    $sql = "INSERT INTO wp_posts (post_author,post_date,post_date_gmt,post_content,post_title,post_excerpt,post_status,comment_status,ping_status,post_password,post_name,to_ping,pinged,post_modified,post_modified_gmt,post_content_filtered,post_parent,guid,menu_order,post_type,post_mime_type,comment_count) VALUES (";
    $sql .= "'" . $file_data["post_author"] . "','" . $file_data["post_date"] . "','" . $file_data["post_date_gmt"] . "','" . $file_data["post_content"] . "','" . $file_data["post_title"] . "','" . $file_data["post_excerpt"] . "','" . $file_data["post_status"] . "','" . $file_data["comment_status"] . "','" . $file_data["ping_status"] . "','" . $file_data["post_password"] . "','" . $file_data["post_name"] . "','" . $file_data["to_ping"] . "','" . $file_data["pinged"] . "','" . $file_data["post_modified"] . "','" . $file_data["post_modified_gmt"] . "','" . $file_data["post_content_filtered"] . "','";
    foreach($replace_post_ids as $replace_post_id){
        if($replace_post_id["before"] == $file_data["post_parent"]){
            $file_data["post_parent"] = $replace_post_id["after"];
        }
    }
    $sql .= $file_data["post_parent"] . "','";
    $sql .= str_replace($old_file_path, $new_file_path, $file_data["guid"]) . "','";
    $sql .= $file_data["menu_order"] . "','" . $file_data["post_type"] . "','" . $file_data["post_mime_type"] . "','" . $file_data["comment_count"] . "')";
    $result = mysqli_query(
        $link,
        $sql,
        MYSQLI_STORE_RESULT
    );
    if($result){
        $get_autoincriment_id = mysqli_query(
            $link,
            'SELECT LAST_INSERT_ID();',
            MYSQLI_STORE_RESULT
        );
        $autoincriment_id = $get_autoincriment_id->fetch_all(MYSQLI_ASSOC); 
        return $autoincriment_id; 
    }else{
        $status_json["error"][] = "wp_postsへ画像・ファイルの情報が挿入できませんでした";
        return false;
    }
}

/**
 * wp_postmetaへのInsert（画像やファイル）
 * $postmeta_data : postのデータ配列
 * $replace_post_ids : 取得先DBと移行先DBのpost_id対照表
 * $replace_post_ids : 取得先DBと移行先DBのpost_id対照表（画像やファイル）
 * $link : MySQLサーバーへの接続オブジェクト
 */
function insert_postmeta(array $postmeta_data, array $replace_post_ids, array $replace_file_ids, $link){
    global $status_json;
    $sql = "INSERT INTO wp_postmeta (post_id,meta_key,meta_value) VALUES (";
    foreach($replace_post_ids as $replace_post_id){
        if($replace_post_id["before"] == $postmeta_data["post_id"]){
            $postmeta_data["post_id"] = $replace_post_id["after"];
        }
    }
    $sql .= $postmeta_data["post_id"] . ",'";
    $sql .= $postmeta_data["meta_key"] . "','";
    foreach($replace_file_ids as $replace_file_id){
        if($replace_file_id["before"] == $postmeta_data["meta_value"]){
            $postmeta_data["meta_value"] = $replace_file_id["after"];
        }
    }
    $sql .= $postmeta_data["meta_value"];
    $sql .= "')";
    $result = mysqli_query(
        $link,
        $sql,
        MYSQLI_STORE_RESULT
    );
    if ($result) {
        $status_json["result"][] = "DBへの挿入が完了しました。";
    }else{
        $status_json["error"][] = "wp_postmetaへ画像・ファイルの情報が挿入できませんでした";
    }
}

/**
 * 指定したカテゴリorタグ（ターム）を持つ投稿のレコードを全て取得
 * $taxonomy_type : タームの種類(category or post_tag)
 * $terms : タームの配列
 * $exception_$terms : この配列に含まれるタームを持っている投稿は取得しない
 * $link : MySQLサーバーへの接続オブジェクト
 */
function get_posts_by_terms(string $taxonomy_type, array $terms, $link, array $exception_terms = [])
{
    $sql = "
        SELECT DISTINCT ID,post_author,post_date,post_date_gmt,post_content,post_title,post_excerpt,post_status,comment_status,ping_status,post_password,post_name,to_ping,pinged,post_modified,post_modified_gmt,post_content_filtered,post_parent,guid,menu_order,post_type,post_mime_type,comment_count
        FROM wp_posts
        INNER JOIN wp_term_relationships ON wp_term_relationships.object_id = wp_posts.ID
        INNER JOIN wp_terms ON wp_terms.term_id = wp_term_relationships.term_taxonomy_id
        INNER JOIN wp_term_taxonomy ON wp_terms.term_id = wp_term_taxonomy.term_id
        WHERE (wp_posts.post_status = 'publish' OR wp_posts.post_status = 'private') AND wp_posts.post_type = 'post'
        ";
    $sql .= " AND wp_term_taxonomy.taxonomy = '" . $taxonomy_type . "' AND (";
    $first = true;
    foreach($terms as $term){
        if(!$first){
            $sql .= "OR";
        }
        $sql .= " wp_term_relationships.term_taxonomy_id = '" . $term . "' ";
        $first =  false;
    }
    $sql .= ")";
    if(!empty($exception_terms)){
        $sql .= " AND ";
        $first = true;
        foreach($exception_terms as $exception_term){
            if(!$first){
                $sql .= "OR";
            }
            $sql .= " wp_term_relationships.term_taxonomy_id != '" . $exception_term . "' ";
            $first =  false;
        }
        $sql .= ")";
    }
    $result = mysqli_query(
        $link,
        $sql,
        MYSQLI_STORE_RESULT
    );
    $result_array = $result->fetch_all(MYSQLI_ASSOC);
    return $result_array;
}

/**
 * 指定したIDに紐づくpostmetaテーブルのレコードを全て取得
 * $ids : idの配列
 * $meta_keys : 欲しいmeta_keyの配列
 * $link : MySQLサーバーへの接続オブジェクト
 */
function get_postsmeta_by_ids(array $ids, array $meta_keys, $link)
{
    $sql = "
        SELECT *
        FROM wp_postmeta
        WHERE (
        ";
    $first = true;
    foreach($ids as $id){
        if(!$first){
            $sql .= "OR";
        }
        $sql .= " post_id = '" . $id . "' ";
        $first =  false;
    }
    $sql .= ") AND (";

    $first = true;
    foreach($meta_keys as $meta_key){
        if(!$first){
            $sql .= "OR";
        }
        $sql .= " meta_key = '" . $meta_key . "' ";
        $first =  false;
    }
    $sql .= ")";
    $sql .= " AND meta_value != ''";

    $result = mysqli_query(
        $link,
        $sql,
        MYSQLI_STORE_RESULT
    );
    $result_array = $result->fetch_all(MYSQLI_ASSOC);
    return $result_array;
}

/**
 * 指定したIDに紐づくwp_termsテーブルのレコードを全て取得
 * $ids : idの配列
 * $link : MySQLサーバーへの接続オブジェクト
 */
function get_terms_by_ids(array $ids, $link)
{
    $sql = "
        SELECT DISTINCT term_id,name,slug,term_group,wp_terms.term_order
        FROM wp_terms
        INNER JOIN wp_term_relationships ON wp_terms.term_id = wp_term_relationships.term_taxonomy_id
        WHERE
        ";
    $first = true;
    foreach($ids as $id){
        if(!$first){
            $sql .= "OR";
        }
        $sql .= " wp_term_relationships.object_id = '" . $id . "' ";
        $first =  false;
    }

    $result = mysqli_query(
        $link,
        $sql,
        MYSQLI_STORE_RESULT
    );
    $result_array = $result->fetch_all(MYSQLI_ASSOC);
    return $result_array;
}

/**
 * 指定したIDに紐づくwp_term_relationshipsテーブルのレコードを全て取得
 * $ids : idの配列
 * $link : MySQLサーバーへの接続オブジェクト
 */
function get_term_relationships_by_ids(array $ids, $link)
{
    $sql = "
        SELECT DISTINCT wp_terms.name,object_id,term_taxonomy_id,wp_term_relationships.term_order
        FROM wp_term_relationships
        INNER JOIN wp_terms ON wp_terms.term_id = wp_term_relationships.term_taxonomy_id
        WHERE
        ";
    $first = true;
    foreach($ids as $id){
        if(!$first){
            $sql .= "OR";
        }
        $sql .= " wp_term_relationships.object_id = '" . $id . "' ";
        $first =  false;
    }
    $result = mysqli_query(
        $link,
        $sql,
        MYSQLI_STORE_RESULT
    );
    $result_array = $result->fetch_all(MYSQLI_ASSOC);
    return $result_array;
}

/**
 * 指定したIDに紐づくwp_term_taxonomyテーブルのレコードを全て取得
 * $ids : idの配列
 * $convert_taxonomy : ["before" => "xxx","after" => "xxx"]のようなtermを何から何に変換するか設定の配列
 * $link : MySQLサーバーへの接続オブジェクト
 */
function get_term_taxonomy_by_ids(array $ids, array $convert_taxonomy, $link)
{
    $sql = "
        SELECT DISTINCT wp_term_taxonomy.term_taxonomy_id,term_id,taxonomy,description,parent,count
        FROM wp_term_taxonomy
        INNER JOIN wp_term_relationships ON wp_term_taxonomy.term_id = wp_term_relationships.term_taxonomy_id
        WHERE
        ";
    $first = true;
    foreach($ids as $id){
        if(!$first){
            $sql .= "OR";
        }
        $sql .= " wp_term_relationships.object_id = '" . $id . "' ";
        $first =  false;
    }

    $result = mysqli_query(
        $link,
        $sql,
        MYSQLI_STORE_RESULT
    );
    $result_array = $result->fetch_all(MYSQLI_ASSOC);
    for($i = 0; $i < count($result_array); $i++){
            $result_array[$i]["taxonomy"] = str_replace($convert_taxonomy["before"],$convert_taxonomy["after"],$result_array[$i]["taxonomy"]);
    }
    return $result_array;
}


/**
 * 指定したIDに紐づく画像やファイルのレコードを全て取得（wp_posts）
 * $ids : idの配列
 * $file_keys : ファイルや画像のidが入ってくるmeta_key項目の配列
 * $link : MySQLサーバーへの接続オブジェクト
 */
function get_files_by_ids(array $ids, array $file_keys, $link)
{
    //画像idの一覧を取り出す
    $sql = "
        SELECT meta_value
        FROM wp_postmeta
        WHERE
    ";
    $sql .= " ( ";
    $first = true;
    foreach($file_keys as $file_key){
        if(!$first){
            $sql .= "OR";
        }
        $sql .= " meta_key = '" . $file_key . "' ";
        $first =  false;
    }
    $sql .= ") AND";
    $sql .= " ( ";
    $first = true;
    foreach($ids as $id){
        if(!$first){
            $sql .= "OR";
        }
        $sql .= " post_id = '" . $id . "' ";
        $first =  false;
    }
    $sql .= ")";
    $result = mysqli_query(
        $link,
        $sql,
        MYSQLI_STORE_RESULT
    );
    $file_results = $result->fetch_all(MYSQLI_ASSOC);
    $file_ids = array();
    foreach($file_results as $file_result){
        if($file_result["meta_value"] != ""){
            array_push($file_ids,$file_result["meta_value"]);
        }
    }

    //wp_postsからファイルのレコードを取得
    $result_array = array();
    if(!empty($file_ids)){
        $sql = "
            SELECT *
            FROM wp_posts
            WHERE post_type = 'attachment'
        ";
        $sql .= " AND ( ";
        $first = true;
        foreach($file_ids as $file_id){
            if(!$first){
                $sql .= "OR";
            }
            $sql .= " ID = '" . $file_id . "' ";
            $first =  false;
        }
        $sql .= " ) ";
        $result = mysqli_query(
            $link,
            $sql,
            MYSQLI_STORE_RESULT
        );
        $result_array = $result->fetch_all(MYSQLI_ASSOC);
    }
    return $result_array;
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
 * json作成（actionの内容作成）
 * $grand_parent_id : 最上位の親ページのpost_idか文字列（int or string）交差型はPHP 8.1.0以降しか使えないのでやめておく
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