<?php

namespace UpyunFile;

use Typecho\Db;
use Typecho\Plugin;
use Typecho\Widget;
use Typecho\Widget\Helper\Form;
use Widget\Base\Options;
use Widget\Notice;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * UpyunFile 插件 Action 处理
 */
class Action extends Widget
{
    /**
     * 绑定动作
     */
    public function action()
    {
        $this->user->pass('administrator');
        $this->security->protect();

        $this->on($this->request->is('do=backup'))->backup();
        $this->on($this->request->is('do=restore'))->restore();
        $this->on($this->request->is('do=delete'))->delete();

        // 默认返回插件配置页
        $this->response->redirect(Options::alloc()->adminUrl . 'options-plugin.php?config=UpyunFile');
    }

    /**
     * 备份配置
     */
    public function backup()
    {
        $name = 'UpyunFile';
        $db = Db::get();

        // 获取当前配置
        $sjdq = $db->fetchRow($db->select()->from('table.options')->where('name = ?', 'plugin:' . $name));
        $ysj = $sjdq['value'] ?? '';

        if (empty($ysj)) {
            Notice::alloc()->set(_t('没有可备份的配置'), 'error');
        } else {
            $backupKey = 'plugin:' . $name . 'bf';

            // 检查是否已存在备份
            if ($db->fetchRow($db->select()->from('table.options')->where('name = ?', $backupKey))) {
                // 更新现有备份
                $db->query($db->update('table.options')->rows(['value' => $ysj])->where('name = ?', $backupKey));
                Notice::alloc()->set(_t('备份更新成功'), 'success');
            } else {
                // 创建新备份
                $db->query($db->insert('table.options')->rows([
                    'name' => $backupKey,
                    'user' => '0',
                    'value' => $ysj
                ]));
                Notice::alloc()->set(_t('备份成功'), 'success');
            }
        }

        $this->response->redirect(Options::alloc()->adminUrl . 'options-plugin.php?config=UpyunFile');
    }

    /**
     * 还原配置
     */
    public function restore()
    {
        $name = 'UpyunFile';
        $db = Db::get();
        $backupKey = 'plugin:' . $name . 'bf';

        // 检查是否存在备份
        $sjdub = $db->fetchRow($db->select()->from('table.options')->where('name = ?', $backupKey));

        if (!$sjdub) {
            Notice::alloc()->set(_t('未备份过数据，无法恢复'), 'error');
        } else {
            $bsj = $sjdub['value'];
            // 还原配置
            $db->query($db->update('table.options')->rows(['value' => $bsj])->where('name = ?', 'plugin:' . $name));
            Notice::alloc()->set(_t('还原成功'), 'success');
        }

        $this->response->redirect(Options::alloc()->adminUrl . 'options-plugin.php?config=UpyunFile');
    }

    /**
     * 删除备份
     */
    public function delete()
    {
        $name = 'UpyunFile';
        $db = Db::get();
        $backupKey = 'plugin:' . $name . 'bf';

        // 检查是否存在备份
        if ($db->fetchRow($db->select()->from('table.options')->where('name = ?', $backupKey))) {
            $db->query($db->delete('table.options')->where('name = ?', $backupKey));
            Notice::alloc()->set(_t('删除成功'), 'success');
        } else {
            Notice::alloc()->set(_t('没有备份内容，无法删除'), 'error');
        }

        $this->response->redirect(Options::alloc()->adminUrl . 'options-plugin.php?config=UpyunFile');
    }
}
