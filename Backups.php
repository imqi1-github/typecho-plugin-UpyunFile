<?php

namespace UpyunFile;

use Typecho\Db;
use Utils\Helper;

if (!defined('__TYPECHO_ROOT_DIR__')) {
  exit;
}

$name = 'UpyunFile';
$db = Db::get();

// 获取当前配置
$sjdq = $db->fetchRow($db->select()->from('table.options')->where('name = ?', 'plugin:' . $name));
$ysj = $sjdq['value'] ?? '';

if (isset($_POST['type'])) {

  /* 备份数据 */
  if ($_POST["type"] == "备份插件") {
    if ($db->fetchRow($db->select()->from('table.options')->where('name = ?', 'plugin:' . $name . 'bf'))) {
      $update = $db->update('table.options')->rows(array('value' => $ysj))->where('name = ?', 'plugin:' . $name . 'bf');
      $updateRows = $db->query($update); ?>
      <script>
        alert("备份更新成功！");
        window.location.href = '<?php Helper::options()->adminUrl('options-plugin.php?config=UpyunFile'); ?>'
      </script>
    <?php } else {
      if ($ysj) {
        $insert = $db->insert('table.options')->rows(array('name' => 'plugin:' . $name . 'bf', 'user' => '0', 'value' => $ysj));
        $insertId = $db->query($insert); ?>
        <script>
          alert("备份成功！");
          window.location.href = '<?php Helper::options()->adminUrl('options-plugin.php?config=UpyunFile'); ?>'
        </script>
      <?php }
    }
  }

  /* 还原备份 */
  if ($_POST["type"] == "还原备份") {
    if ($db->fetchRow($db->select()->from('table.options')->where('name = ?', 'plugin:' . $name . 'bf'))) {
      $sjdub = $db->fetchRow($db->select()->from('table.options')->where('name = ?', 'plugin:' . $name . 'bf'));
      $bsj = $sjdub['value'];
      $update = $db->update('table.options')->rows(array('value' => $bsj))->where('name = ?', 'plugin:' . $name);
      $updateRows = $db->query($update); ?>
      <script>
        alert("还原成功！");
        window.location.href = '<?php Helper::options()->adminUrl('options-plugin.php?config=UpyunFile'); ?>'
      </script>
    <?php } else { ?>
      <script>
        alert("未备份过数据，无法恢复！");
        window.location.href = '<?php Helper::options()->adminUrl('options-plugin.php?config=UpyunFile'); ?>'
      </script>
    <?php } ?>
  <?php } ?>

  <!-- 删除备份 -->
  <?php if ($_POST["type"] == "删除备份") {
    if ($db->fetchRow($db->select()->from('table.options')->where('name = ?', 'plugin:' . $name . 'bf'))) {
      $delete = $db->delete('table.options')->where('name = ?', 'plugin:' . $name . 'bf');
      $deletedRows = $db->query($delete); ?>
      <script>
        alert("删除成功");
        window.location.href = '<?php Helper::options()->adminUrl('options-plugin.php?config=UpyunFile'); ?>'
      </script>
    <?php } else { ?>
      <script>
        alert("没有备份内容，无法删除！");
        window.location.href = '<?php Helper::options()->adminUrl('options-plugin.php?config=UpyunFile'); ?>'
      </script>
    <?php } ?>
  <?php } ?>
<?php } ?>

  <?php
echo '
<div class="typecho-option">
  <label class="typecho-label">插件备份</label>
  <form action="?config=UpyunFile" method="post">
    <input class="btn" type="submit" name="type" value="备份插件" />
    <input class="btn" type="submit" name="type" value="还原备份" />
    <input class="btn" type="submit" name="type" value="删除备份" />
  </form>
</div>';

