import "./App.css";
import Extract from "./Extract.js";
const App = () => {
  return (
    <div>
      <h2 className="headLineUnderLineLarge">DB分割、階層移動、文字列置換</h2>
      <p className="caption">
        ※ setting.json , adjust.json , replace.json で設定してください。
      </p>
      <Extract />
    </div>
  );
};

export default App;
