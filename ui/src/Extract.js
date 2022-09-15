import "./App.css";
import { useState } from "react";
import axios from "axios";

const Extract = () => {
  const [status, setStatus] = useState("");
  let baseURL = window.location.protocol + "//" + window.location.host;
  if (!baseURL.match(/HierarchychangeExportForWP/)) {
    baseURL += "/HierarchychangeExportForWP/";
  }
  const extractExe = () => {
    setStatus("loading");
    const url = baseURL + "posts-extract.php";
    axios
      .get(url)
      .then((res) => {
        console.log(res);
        const error = res.data.error;
        const sql = res.data.sql;
        const details = res.data.status;
        let text = "";
        if (error.length) {
          error.forEach((val) => {
            text += '<p class="error">' + val + "</p>";
          });
        }
        if (sql.length) {
          text += '<div class="status__sql">';
          sql.forEach((val) => {
            text +=
              "<p>" +
              baseURL +
              val.replace("./", "") +
              " <br><span>にSQLファイルを出力しました。</span></p>";
          });
          text += "</div>";
        }
        if (Object.keys(details).length) {
          text += '<div class="status__detail">';
          for (const grandParentId in details) {
            <h2 class="headLineUnderLineLarge">
              DB分割、階層移動、文字列置換
            </h2>;
            text +=
              '<h3 class="headLineBar">' +
              details[grandParentId].top_hierarchy +
              " の変更内容</h3>";
            const actions = details[grandParentId];
            for (const actionIds in actions) {
              if (actionIds !== "top_hierarchy") {
                text += makeStatusByAction(actionIds, actions[actionIds]);
              }
            }
          }
          text += "</div>";
        }
        setStatus(text);
      })
      .catch((e) => {
        return e;
      });
  };

  const makeStatusByAction = (postId, actions) => {
    let returnText = "";
    let replaceFlg = 0;
    actions.action.forEach((cat) => {
      if (cat === "削除") {
        returnText +=
          '<p class="textDelete">' +
          actions.post_title +
          "<span>（id : " +
          postId +
          "）</span>を削除しました。</p>";
      }
      if (cat === "移動") {
        returnText +=
          '<p class="textMove">' +
          actions.post_title +
          "<span>（id : " +
          postId +
          "）</span>を別の階層に移動しました。</p>";
      }
      if (cat === "最上位移動") {
        returnText +=
          '<p class="textMove">' +
          actions.post_title +
          "<span>（id : " +
          postId +
          "）</span>を最上位階層に移動しました。</p>";
      }
      if (cat === "追加") {
        const add = actions.add;
        for (const addId in add) {
          returnText +=
            '<p class="textAdd">' +
            actions.post_title +
            "<span>（id : " +
            postId +
            "）</span>の子階層に " +
            add[addId] +
            "<span>（id : " +
            addId +
            "）</span> を移動しました。</p>";
        }
      }
      if (cat === "新DBに追加") {
        returnText +=
          '<p class="textAdd">' +
          actions.post_title +
          "<span>（id : " +
          postId +
          "）</span>をこのDBへ移動しました。</p>";
      }
      if (cat === "単純置換") {
        if (replaceFlg === 0) {
          returnText +=
            '<h4 class="headLineText">' +
            actions.post_title +
            "<span>（id : " +
            postId +
            "）</span></h4>";
        }
        returnText += '<ul class="replaceList">';
        actions.replace.forEach((val) => {
          returnText +=
            "<li><span>" +
            val[0] +
            "</span>を<span>" +
            val[1] +
            "</span>に置換しました。<br>";
          returnText += "置換個数 : " + val[2] + " 個</li>";
        });
        returnText += "</ul>";
        replaceFlg = 1;
      }
      if (cat === "正規表現置換") {
        if (replaceFlg === 0) {
          returnText +=
            '<h4 class="headLineText">' +
            actions.post_title +
            "<span>（id : " +
            postId +
            "）</span></h4>";
        }
        returnText += '<ul class="replaceList replaceList--regex">';
        actions.regex.forEach((val) => {
          returnText +=
            "<li><span>" +
            val[0] +
            "</span>を<span>" +
            val[1] +
            "</span>に正規表現を使って置換しました。<br>";
          returnText += "置換個数 : " + val[2] + " 個";
        });
        returnText += "</ul>";
        replaceFlg = 1;
      }
    });
    return returnText;
  };
  return (
    <section>
      <button
        className="buttonExport"
        onClick={() => {
          extractExe();
        }}
      >
        SQLファイル出力
      </button>
      {(() => {
        if (status !== "" && status !== "loading") {
          return (
            <div
              className="status"
              dangerouslySetInnerHTML={{ __html: status }}
            />
          );
        } else if (status === "loading") {
          return <div className="loader"></div>;
        }
      })()}
    </section>
  );
};

export default Extract;
