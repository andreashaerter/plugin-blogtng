<?php
/**
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author     Esther Brunner <wikidesign@gmail.com>
 */

// must be run within Dokuwiki
if (!defined('DOKU_INC')) die();

if (!defined('DOKU_PLUGIN')) define('DOKU_PLUGIN',DOKU_INC.'lib/plugins/');

require_once(DOKU_PLUGIN.'admin.php');

class admin_plugin_blogtng extends DokuWiki_Admin_Plugin {

    var $commenthelper = null;
    var $entryhelper   = null;
    var $sqlitehelper  = null;
    var $taghelper     = null;

    function getInfo() {
        return confToHash(dirname(__FILE__).'/../INFO');
    }

    function getMenuSort() { return 200; }
    function forAdminOnly() { return false; }

    function admin_plugin_blogtng() {
        $this->commenthelper =& plugin_load('helper', 'blogtng_comments');
        $this->entryhelper   =& plugin_load('helper', 'blogtng_entry');
        $this->sqlitehelper  =& plugin_load('helper', 'blogtng_sqlite');
        $this->taghelper     =& plugin_load('helper', 'blogtng_tags');
    }

    /**
     * Handles all actions of the admin component
     *
     * @author Michael Klier <chi@chimeric.de>
     */
    function handle() {
        global $lang;

        $admin = (is_array($_REQUEST['btng']['admin'])) ? key($_REQUEST['btng']['admin']) : $_REQUEST['btng']['admin'];

        // handle actions
        switch($admin) {

            case 'comment_save':
                // FIXME error handling?
                $comment = $_REQUEST['btng']['comment'];
                $this->commenthelper->save($comment);
                msg($this->getLang('msg_comment_save'), 1);
                break;

            case 'comment_delete':
                // FIXME error handling
                $comment = $_REQUEST['btng']['comment'];
                $this->commenthelper->delete($comment['cid']); 
                msg($this->getLang('msg_comment_delete'), 1);
                break;

            case 'comment_batch_edit':
                $batch = $_REQUEST['btng']['admin']['comment_batch_edit'];
                $cids  = $_REQUEST['btng']['comments']['cids'];
                if($cids) {
                    foreach($cids as $cid) {
                        switch($batch) {
                            // FIXME messages
                            case 'delete':
                                $this->commenthelper->delete($cid);
                                msg($this->getLang('msg_comment_delete', 1));
                                break;
                            case 'status_hidden':
                                $this->commenthelper->moderate($cid, 'hidden');
                                msg($this->getLang('msg_comment_status_change'), 1);
                                break;
                            case 'status_visible':
                                $this->commenthelper->moderate($cid, 'visible');
                                msg($this->getLang('msg_comment_status_change'), 1);
                                break;
                        }
                    }
                }
                break;

            case 'entry_set_blog':
                // FIXME errors?
                $pid = $_REQUEST['btng']['entry']['pid'];
                $blog = $_REQUEST['btng']['entry']['blog'];
                if($pid) {
                    $blogs = $this->entryhelper->get_blogs();
                    if(in_array($blog, $blogs)) {
                        $this->entryhelper->load_by_pid($pid);
                        $this->entryhelper->entry['blog'] = $blog;
                        $this->entryhelper->save();
                    }
                }
                msg('set blog for entry', 1);
                break;

            default:
                // do nothing - show dashboard
                break;
        }
    }

    /**
     * Handles the XHTML output of the admin component
     *
     * @author Michael Klier <chi@chimeric.de>
     */
    function html() {
        global $conf;
        global $lang;

        ptln('<h1>'.$this->getLang('menu').'</h1>');

        $admin = (is_array($_REQUEST['btng']['admin'])) ? key($_REQUEST['btng']['admin']) : $_REQUEST['btng']['admin'];

        // display search form
        $this->xhtml_searchform();

        // display link back to dashboard
        if($admin) {
            ptln('<div class="level1">');
            ptln('<p><a hreF="' . wl(DOKU_SCRIPT, array('do'=>'admin', 'page'=>'blogtng')) . '" title="' . $this->getLang('dashboard') . '">&larr; ' . $this->getLang('dashboard') . '</a></p>');
            ptln('</div>');

        }

        switch($admin) {
            case 'search':

                ptln('<h2>' . $this->getLang('searchresults') . '</h2>');
                $query = $_REQUEST['btng']['query'];

                switch($query['filter']) {
                    case 'titles':
                        $this->xhtml_search_titles($query);
                        break;
                    case 'comments':
                        $this->xhtml_search_comments($query);
                        break;
                    case 'tags':
                        $this->xhtml_search_tags($query);
                        break;
                }

                break;

            case 'comment_edit':
            case 'comment_preview':
                if($admin == 'comment_edit') {
                    $obj = $this->commenthelper->comment_by_cid($_REQUEST['btng']['comment']['cid']);
                    $comment = $obj->data;
                    if($comment) {
                        $this->xhtml_comment_edit_form($comment);
                    }
                }
                if($admin == 'comment_preview') {
                    $this->xhtml_comment_edit_form($_REQUEST['btng']['comment']);
                    $this->xhtml_comment_preview($_REQUEST['btng']['comment']);
                }
                break;

            default:
                // print latest entries/commits
                printf('<h2>'.$this->getLang('comment_latest').'</h2>', 5);
                $this->xhtml_comment_latest();
                printf('<h2>'.$this->getLang('entry_latest').'</h2>', 5);
                $this->xhtml_entry_latest();
                break;
        }
    }

    /**
     * Displays a list of entries for a given matching title search
     *
     * @author Michael Klier <chi@chimeric.de>
     */
    function xhtml_search_titles($data) {
        $query = 'SELECT * FROM entries ';
        if($data['blog']) {
            $query .= 'WHERE blog = "' . $data['blog'] . '" ';
        } else {
            $query .= 'WHERE blog != ""';
        } 
        $query .= 'AND ( title LIKE "%'.$data['string'].'%" ) ';
        $query .= 'ORDER BY created DESC ';

        $resid = $this->sqlitehelper->query($query);
        if($resid) {
            $this->xhtml_search_result($resid, $data, 'xhtml_entry_list');
        }
    }

    /**
     * Displays a list of comments for a given search term
     *
     * @author Michael Klier <chi@chimeric.de>
     */
    function xhtml_search_comments($data) {
        $query = 'SELECT DISTINCT cid, B.pid as pid, source, name, B.mail as mail, web, avatar, B.created as created, text, status 
                  FROM comments B LEFT JOIN entries A ON B.pid = A.pid ';
        if($data['blog']) {
            $query .= 'WHERE blog = "' . $data['blog'] . '" ';
        } else {
            $query .= 'WHERE blog != ""';
        } 

        // check for search query
        if(isset($data['string'])) {
            $query .= 'AND ( B.text LIKE "%'.$data['string'].'%" ) ';
        }

        // if pid is given limit to give page
        if(isset($data['pid'])) {
            $query .= 'AND ( B.pid = "' . $data['pid'] . '" ) ';
        } 

        $query .= 'ORDER BY B.created DESC';

        $resid = $this->sqlitehelper->query($query);
        if($resid) {
            $this->xhtml_search_result($resid, $data, 'xhtml_comment_list');
        }
    }

    /**
     * Query the tag database for a give search string
     *
     * @author Michael Klier <chi@chimeric.de>
     */
    function xhtml_search_tags($data) {
        $query = 'SELECT DISTINCT A.pid as pid, page, title, blog, image, created, lastmod, author, login, mail
                  FROM entries A LEFT JOIN tags B ON A.pid = B.pid ';
        if($data['blog']) {
            $query .= 'WHERE blog = "' . $data['blog'] . '" ';
        } else {
            $query .= 'WHERE blog != ""';
        } 
        $query .= 'AND ( B.tag LIKE "%'.$data['string'].'%" ) ';
        $query .= 'ORDER BY created DESC ';

        $resid = $this->sqlitehelper->query($query);
        if($resid) {
            $this->xhtml_search_result($resid, $data, 'xhtml_entry_list');
        }
    }

    function xhtml_search_result($resid, $query, $callback) {
        if(!$resid) return;

        // FIXME selectable?
        $limit = 20;

        $count = sqlite_num_rows($resid);
        $start = (isset($_REQUEST['btng']['query']['start'])) ? ($_REQUEST['btng']['query']['start'])  : 0;
        $end   = ($count >= ($start + $limit)) ? ($start + $limit) : $count;
        $cur   = ($start / $limit) + 1;

        $items = array();
        for($i = $start; $i < $end; $i++) {
            $items[] = $this->sqlitehelper->res2row($resid, $i);
        }

        if($items) {
            ptln('<div class="level2"><p><strong>' . $this->getLang('numhits') . ':</strong> ' . $count .'</p></div>');
            call_user_func(array($this, $callback), $items);
        } else {
            ptln('<div class="level2">');
            ptln($lang['nothingfound']);
            ptln('</div>');
        }

        // show pagination only when enough items
        if($count < $limit) return;
        $this->xhtml_pagination($query, $cur, $start, $count, $limit);
    }

    function xhtml_pagination($query, $cur, $start, $count, $limit) {
        // FIXME

        $max = ceil($count / $limit);

        $pages[] = 1;     // first always
        $pages[] = $max;  // last page always
        $pages[] = $cur;  // current always

        if($max > 1){            // if enough pages
            $pages[] = 2;        // second and ..
            $pages[] = $max-1;   // one before last
        }

        // three around current
        if($cur-1 > 0) $pages[] = $cur-1;
        if($cur-2 > 0) $pages[] = $cur-2;
        if($cur-3 > 0) $pages[] = $cur-3;
        if($cur+1 < $max) $pages[] = $cur+1;
        if($cur+2 < $max) $pages[] = $cur+2;
        if($cur+3 < $max) $pages[] = $cur+3;

        $pages = array_unique($pages);
        sort($pages);

        ptln('<div class="level2">');

        if($cur > 1) {
            ptln('<a href="' . wl($ID, array('do'=>'admin', 
                                             'page'=>'blogtng', 
                                             'btng[admin]'=>'search', 
                                             'btng[query][filter]'=>$query['filter'], 
                                             'btng[query][blog]'=>$query['blog'], 
                                             'btng[query][string]'=>$query['string'], 
                                             'btng[query][start]'=>(($cur-2)*$limit))) . '" title="' . ($cur-1) . '">&laquo;</a>');
        }

        $last = 0;
        foreach($pages as $page) {
            if($page - $last > 1) {
                ptln('<span class="sep">...</span>');
            }
            if($page == $cur) {
                ptln('<span class="cur">' . $page . '</span>');
            } else {
                ptln('<a href="' . wl($ID, array('do'=>'admin', 
                                                 'page'=>'blogtng', 
                                                 'btng[admin]'=>'search', 
                                                 'btng[query][filter]'=>$query['filter'], 
                                                 'btng[query][blog]'=>$query['blog'], 
                                                 'btng[query][string]'=>$query['string'], 
                                                 'btng[query][start]'=>(($page-1)*$limit))) . '" title="' . $page . '">' . $page . '</a>');
            }
            $last = $page;
        }

        if($cur < $max) {
            ptln('<a href="' . wl($ID, array('do'=>'admin', 
                                             'page'=>'blogtng', 
                                             'btng[admin]'=>'search', 
                                             'btng[query][filter]'=>$query['filter'], 
                                             'btng[query][blog]'=>$query['blog'], 
                                             'btng[query][string]'=>$query['string'], 
                                             'btng[query][start]'=>($cur*$limit))) . '" title="' . ($cur+1) . '">&raquo;</a>');
        }

        ptln('</div>');
    }

    /**
     * Displays the latest blog entries
     * 
     * @author Michael Klier <chi@chimeric.de>
     */
    function xhtml_entry_latest() {
        $limit = 5;

        $query = 'SELECT * 
                    FROM entries
                   WHERE blog != ""
                ORDER BY created DESC
                   LIMIT ' . $limit;

        $resid = $this->sqlitehelper->query($query);
        if(!$resid) return;
        $this->xhtml_search_result($resid, array(), 'xhtml_entry_list');
    }

    /**
     * Display the latest comments
     *
     * @author Michael Klier <chi@chimeric.de>
     */
    function xhtml_comment_latest() {
        $limit = 5;

        $query = 'SELECT * 
                    FROM comments
                ORDER BY created DESC
                   LIMIT ' . $limit;

        $resid = $this->sqlitehelper->query($query);
        if(!$resid) return;
        $this->xhtml_search_result($resid, array(), 'xhtml_comment_list');
    }

    /**
     * Displays a list of entries
     *
     * @author Michael Klier <chi@chimeric.de>
     */
    function xhtml_entry_list($entries) {
        ptln('<div class="level2">');
        ptln('<table class="inline">');

        // FIXME language strings
        ptln('<th>' . $this->getLang('entry') . '</th>');
        ptln('<th>' . $this->getLang('blog') . '</th>');
        ptln('<th>' . $this->getLang('comments') . '</th>');
        ptln('<th>' . $this->getLang('tags') . '</th>');
        ptln('<th></th>');
        foreach($entries as $entry) {
            $this->xhtml_entry_item($entry);
        }
        ptln('</table>');
        ptln('</div>');
    }

    /**
     * Displays a single entry and related actions
     *
     * @author Michael Klier <chi@chimeric.de>
     */
    function xhtml_entry_item($entry) {
        global $lang;

        ptln('<tr>');

        ptln('<td>' . html_wikilink($entry['page'], $entry['title']) . '</td>');

        ptln('<td>' . $this->xhtml_entry_set_blog_form($entry) . '</th>');

        $this->commenthelper->load($entry['pid']);

        // comments edit link
        ptln('<td>');
        $count = $this->commenthelper->get_count();
        if($count > 0) {
            ptln('<a href="' . wl(DOKU_SCRIPT, array('do'=>'admin', 
                                                     'page'=>'blogtng', 
                                                     'btng[admin]'=>'search', 
                                                     'btng[query][filter]'=>'comments', 
                                                     'btng[query][pid]'=>$entry['pid'])) 
                             . '" title="' . $this->getLang('comments') . '">' . $count . '</a>');
        } else {
            ptln($count);
        }
        ptln('</td>');

        // tags filter links
        ptln('<td>'); 
        $this->taghelper->load($entry['pid']);
        $tags = $this->taghelper->tags;
        $count = count($tags);
        for($i=0;$i<$count;$i++) {
            $link = '<a href="' . wl(DOKU_SCRIPT, array('do'=>'admin',
                                                     'page'=>'blogtng',
                                                     'btng[admin]'=>'search',
                                                     'btng[query][filter]'=>'tags',
                                                     'btng[query][string]'=>$tags[$i]))
                             . '" title="' . $tags[$i] . '">' . $tags[$i] . '</a>';
            if($i<($count-1)) $link .= ', ';
            ptln($link);
        }
        ptln('</td>');

        // edit links
        ptln('<td>');
        ptln('<a href="' . wl(DOKU_SCRIPT, array('id'=>$entry['page'],
                                                 'do'=>'edit')) 
                         . '" class="blogtng_btn_edit" title="' . $lang['btn_secedit'] . '">' . $lang['btn_secedit'] . '</a>');
        ptln('</td>');

        ptln('</tr>');
    }

    /**
     * Displays a list of comments
     *
     * @author Michael Klier <chi@chimeric.de>
     */
    function xhtml_comment_list($comments) {
        global $lang;

        ptln('<div class="level2">');

        ptln('<form action="' . DOKU_SCRIPT . '" method="post" id="blogtng__comment_batch_edit">');
        ptln('<input type="hidden" name="page" value="blogtng" />');

        ptln('<table class="inline">');
        ptln('<th></th>');
        ptln('<th>' . $this->getLang('comment_avatar') . '</th>');
        ptln('<th>' . $this->getLang('comment_date') . '</th>');
        ptln('<th>' . $this->getLang('comment_name') . '</th>');
        ptln('<th>' . $this->getLang('comment_status') . '</th>');
        ptln('<th>' . $this->getLang('comment_source') . '</th>');
        ptln('<th>' . $this->getLang('entry') . '</th>');
        ptln('<th>' . $this->getLang('comment_text') . '</th>');
        ptln('<th></th>');

        foreach($comments as $comment) {
            $this->xhtml_comment_item($comment);
        }

        ptln('</table>');
        ptln('<select name="btng[admin][comment_batch_edit]">');
        ptln('<option value="status_visible">Visible</option>');
        ptln('<option value="status_hidden">Hidden</option>');
        ptln('<option value="delete">'.$lang['btn_delete'].'</option>');
        ptln('</select>');
        ptln('<input type="submit" class="edit button" name="do[admin]" value="' . $lang['btn_update'] . '" />');
        ptln('</form>');

        ptln('</div>');
    }

    /**
     * Displays a single comment and related actions
     *
     * @author Michael Klier <chi@chimeric.de>
     */
    function xhtml_comment_item($comment) {
        global $conf;
        global $lang;

        ptln('<tr>');

        $cmt = new blogtng_comment();
        $cmt->init($comment);
        ptln('<td><input type="checkbox" name="btng[comments][cids][]" value="' . $comment['cid'] . '" /></td>');
        ptln('<td><img src="' . $cmt->tpl_avatar(1,0,true) . '" /></td>');

        ptln('<td>' . strftime($conf['dformat'], $comment['created']) . '</td>');
        ptln('<td>' . $comment['name'] . '</td>');
        ptln('<td>' . $comment['status'] . '</td>');
        ptln('<td>' . $comment['source'] . '</td>');

        $this->entryhelper->load_by_pid($comment['pid']);
        ptln('<td>' . html_wikilink($this->entryhelper->entry['page'], $this->entryhelper->entry['title']) . '</td>');

        ptln('<td>' . $comment['text'] . '</td>');

        ptln('<td><a href="' . wl(DOKU_SCRIPT, array('do'=>'admin', 
                                                     'page'=>'blogtng', 
                                                     'btng[comment][cid]'=>$comment['cid'],
                                                     'btng[admin]'=>'comment_edit')) 
                             . '" class="blogtng_btn_edit" title="' . $lang['btn_edit'] . '">' . $lang['btn_secedit'] . '</a></td>');

        ptln('</tr>');
    }

    function xhtml_comment_preview($data) {
        global $lang;
        // FIXME
        ptln('<h2>' . $lang['btn_preview'] . '</h2>'); 
        ptln('<div class="level2">');
        $comment = new blogtng_comment();
        $comment->init($data);
        $comment->output('default');
        ptln('</div>');
    }

    /**
     * Displays the form to set the blog a entry belongs to
     *
     * @author Michael Klier <chi@chimeric.de>
     */
    function xhtml_entry_set_blog_form($entry) {
        global $lang;
        $blogs = $this->entryhelper->get_blogs();

        $form = new Doku_FOrm(array('id'=>'blogtng__entry_set_blog_form'));
        $form->addHidden('do', 'admin');
        $form->addHidden('page', 'blogtng');
        $form->addHidden('btng[entry][pid]', $entry['pid']);
        $form->addElement(formSecurityToken());
        $form->addElement(form_makeListBoxField('btng[entry][blog]', $blogs, $entry['blog'], ''));
        $form->addElement('<input type="submit" name="btng[admin][entry_set_blog]" class="edit button" value="' . $lang['btn_update'] . '" />');

        ob_start();
        html_form('blotng__btn_entry_set_blog', $form);
        $form = ob_get_clean();
        return $form;
    }

    /**
     * Displays the comment edit form
     *
     * @author Michael Klier <chi@chimeric.de>
     */
    function xhtml_comment_edit_form($comment) {
        global $lang;

        ptln('<h2>' . $this->getLang('act_comment_edit') . '</h2>');
        ptln('<div class="level2">');
        $form = new Doku_Form(array('id'=>'blogtng__comment_edit_form'));
        $form->addHidden('page', 'blogtng');
        $form->addHidden('btng[admin]', $action);
        $form->addHidden('do', 'admin');
        $form->addHidden('btng[comment][cid]', $comment['cid']);
        $form->addHidden('btng[comment][pid]', $comment['pid']);
        $form->addHidden('btng[comment][created]', $comment['created']);
        $form->addElement(formSecurityToken());
        $form->addElement(form_makeListBoxField('btng[comment][status]', array('visible', 'hidden'), $comment['status'], $this->getLang('comment_status')));
        $form->addElement('<br />');
        $form->addElement(form_makeListBoxField('btng[comment][source]', array('comment', 'trackback', 'pingback'), $comment['source'], $this->getLang('comment_source')));
        $form->addElement('<br />');
        $form->addElement(form_makeTextField('btng[comment][name]', $comment['name'], $this->getLang('comment_name')));
        $form->addElement('<br />');
        $form->addElement(form_makeTextField('btng[comment][mail]', $comment['mail'], $this->getLang('comment_mail')));
        $form->addElement('<br />');
        $form->addElement(form_makeTextField('btng[comment][web]', $comment['web'], $this->getLang('comment_web')));
        $form->addElement('<br />');
        $form->addElement(form_makeTextField('btng[comment][avatar]', $comment['avatar'], $this->getLang('comment_avatar')));
        $form->addElement('<br />');
        $form->addElement('<textarea class="edit" name="btng[comment][text]" rows="14" cols="80">' . $comment['text'] . '</textarea>');
        $form->addElement('<input type="submit" name="btng[admin][comment_save]" class="edit button" value="' . $lang['btn_save'] . '" />');
        $form->addElement('<input type="submit" name="btng[admin][comment_preview]" class="edit button" value="' . $lang['btn_preview'] . '" />');
        $form->addElement('<input type="submit" name="btng[admin][comment_delete]" class="edit button" value="' . $lang['btn_delete'] . '" />');
        html_form('blogtng__edit_comment', $form);
        ptln('</div>');
    }

    /**
     * Displays the search form
     *
     * @author Michael Klier <chi@chimeric.de>
     */
    function xhtml_searchform() {
        global $lang;

        ptln('<div class="level1">');

        $blogs = $this->entryhelper->get_blogs();

        $form = new Doku_Form(array('id'=>'blogtng__search_form'));
        $form->addHidden('page', 'blogtng');
        $form->addHidden('btng[admin]', 'search');
        $form->addElement(formSecurityToken());

        $form->addElement(form_makeListBoxField('btng[query][blog]', $blogs, $_REQUEST['btng']['query']['blog'], $this->getLang('blog')));
        $form->addElement(form_makeListBoxField('btng[query][filter]', array('titles', 'comments', 'tags'), $_REQUEST['btng']['query']['filter'], $this->getLang('filter')));
        $form->addElement(form_makeTextField('btng[query][string]', $_REQUEST['btng']['query']['string'],''));

        $form->addElement(form_makeButton('submit', 'admin', $lang['btn_search']));
        html_form('blogtng__search_form', $form);

        ptln('</div>');
    }

}
// vim:ts=4:sw=4:et:enc=utf-8:
