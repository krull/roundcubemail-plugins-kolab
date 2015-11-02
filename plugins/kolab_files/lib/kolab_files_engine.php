<?php

/**
 * Kolab files storage engine
 *
 * @version @package_version@
 * @author Aleksander Machniak <machniak@kolabsys.com>
 *
 * Copyright (C) 2013, Kolab Systems AG <contact@kolabsys.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

class kolab_files_engine
{
    private $plugin;
    private $rc;
    private $timeout = 600;
    private $sort_cols = array('name', 'mtime', 'size');

    const API_VERSION = 2;


    /**
     * Class constructor
     */
    public function __construct($plugin, $url)
    {
        $this->url     = rcube_utils::resolve_url($url);
        $this->plugin  = $plugin;
        $this->rc      = $plugin->rc;
        $this->timeout = $this->rc->config->get('session_lifetime') * 60;
    }

    /**
     * User interface initialization
     */
    public function ui()
    {
        $this->plugin->add_texts('localization/');

        // set templates of Files UI and widgets
        if ($this->rc->task == 'mail') {
            if ($this->rc->action == 'compose') {
                $template = 'compose_plugin';
            }
            else if (in_array($this->rc->action, array('show', 'preview', 'get'))) {
                $template = 'message_plugin';

                if ($this->rc->action == 'get') {
                    // add "Save as" button into attachment toolbar
                    $this->plugin->add_button(array(
                        'id'         => 'saveas',
                        'name'       => 'saveas',
                        'type'       => 'link',
                        'onclick'    => 'kolab_directory_selector_dialog()',
                        'class'      => 'button buttonPas saveas',
                        'classact'   => 'button saveas',
                        'label'      => 'kolab_files.save',
                        'title'      => 'kolab_files.saveto',
                        ), 'toolbar');
                }
                else {
                    // add "Save as" button into attachment menu
                    $this->plugin->add_button(array(
                        'id'         => 'attachmenusaveas',
                        'name'       => 'attachmenusaveas',
                        'type'       => 'link',
                        'wrapper'    => 'li',
                        'onclick'    => 'return false',
                        'class'      => 'icon active saveas',
                        'classact'   => 'icon active saveas',
                        'innerclass' => 'icon active saveas',
                        'label'      => 'kolab_files.saveto',
                        ), 'attachmentmenu');
                }
            }

            $this->folder_list_env();

            $this->plugin->add_label('save', 'cancel', 'saveto',
                'saveall', 'fromcloud', 'attachsel', 'selectfiles', 'attaching',
                'collection_audio', 'collection_video', 'collection_image', 'collection_document',
                'folderauthtitle', 'authenticating'
            );
        }
        else if ($this->rc->task == 'files') {
            $template = 'files';

            // get list of external sources
            $this->get_external_storage_drivers();
        }

        // add taskbar button
        if (empty($_REQUEST['framed'])) {
            $this->plugin->add_button(array(
                'command'    => 'files',
                'class'      => 'button-files',
                'classsel'   => 'button-files button-selected',
                'innerclass' => 'button-inner',
                'label'      => 'kolab_files.files',
                ), 'taskbar');
        }

        $this->plugin->include_stylesheet($this->plugin->local_skin_path().'/style.css');

        if (!empty($template)) {
            $collapsed_folders = (string) $this->rc->config->get('kolab_files_collapsed_folders');

            $this->plugin->include_script($this->url . '/js/files_api.js');
            $this->plugin->include_script('kolab_files.js');
            $this->rc->output->include_script('treelist.js');
            $this->rc->output->set_env('files_url', $this->url . '/api/');
            $this->rc->output->set_env('files_token', $this->get_api_token());
            $this->rc->output->set_env('kolab_files_collapsed_folders', $collapsed_folders);

            // register template objects for dialogs (and main interface)
            $this->rc->output->add_handlers(array(
                'folder-create-form' => array($this, 'folder_create_form'),
                'folder-edit-form'   => array($this, 'folder_edit_form'),
                'folder-mount-form'  => array($this, 'folder_mount_form'),
                'folder-auth-options'=> array($this, 'folder_auth_options'),
                'file-search-form'   => array($this, 'file_search_form'),
                'file-edit-form'     => array($this, 'file_edit_form'),
                'file-create-form'   => array($this, 'file_create_form'),
                'filelist'           => array($this, 'file_list'),
                'filequotadisplay'   => array($this, 'quota_display'),
            ));

            if ($this->rc->task != 'files') {
                // add dialog content at the end of page body
                $this->rc->output->add_footer(
                    $this->rc->output->parse('kolab_files.' . $template, false, false));
            }
        }
    }

    /**
     * Engine actions handler
     */
    public function actions()
    {
        if ($this->rc->task == 'files' && $this->rc->action) {
            $action = $this->rc->action;
        }
        else if ($this->rc->task != 'files' && $_POST['act']) {
            $action = $_POST['act'];
        }
        else {
            $action = 'index';
        }

        $method = 'action_' . str_replace('-', '_', $action);

        if (method_exists($this, $method)) {
            $this->plugin->add_texts('localization/');
            $this->{$method}();
        }
    }

    /**
     * Template object for folder creation form
     */
    public function folder_create_form($attrib)
    {
        $attrib['name'] = 'folder-create-form';
        if (empty($attrib['id'])) {
            $attrib['id'] = 'folder-create-form';
        }

        $input_name    = new html_inputfield(array('id' => 'folder-name', 'name' => 'name', 'size' => 30));
        $select_parent = new html_select(array('id' => 'folder-parent', 'name' => 'parent'));
        $table         = new html_table(array('cols' => 2, 'class' => 'propform'));

        $table->add('title', html::label('folder-name', rcube::Q($this->plugin->gettext('foldername'))));
        $table->add(null, $input_name->show());
        $table->add('title', html::label('folder-parent', rcube::Q($this->plugin->gettext('folderinside'))));
        $table->add(null, $select_parent->show());

        $out = $table->show();

        // add form tag around text field
        if (empty($attrib['form'])) {
            $out = $this->rc->output->form_tag($attrib, $out);
        }

        $this->plugin->add_label('foldercreating', 'foldercreatenotice', 'create', 'foldercreate', 'cancel');
        $this->rc->output->add_gui_object('folder-create-form', $attrib['id']);

        return $out;
    }

    /**
     * Template object for folder editing form
     */
    public function folder_edit_form($attrib)
    {
        $attrib['name'] = 'folder-edit-form';
        if (empty($attrib['id'])) {
            $attrib['id'] = 'folder-edit-form';
        }

        $input_name    = new html_inputfield(array('id' => 'folder-edit-name', 'name' => 'name', 'size' => 30));
        $select_parent = new html_select(array('id' => 'folder-edit-parent', 'name' => 'parent'));
        $table         = new html_table(array('cols' => 2, 'class' => 'propform'));

        $table->add('title', html::label('folder-name', rcube::Q($this->plugin->gettext('foldername'))));
        $table->add(null, $input_name->show());
        $table->add('title', html::label('folder-parent', rcube::Q($this->plugin->gettext('folderinside'))));
        $table->add(null, $select_parent->show());

        $out = $table->show();

        // add form tag around text field
        if (empty($attrib['form'])) {
            $out = $this->rc->output->form_tag($attrib, $out);
        }

        $this->plugin->add_label('folderupdating', 'folderupdatenotice', 'save', 'folderedit', 'cancel');
        $this->rc->output->add_gui_object('folder-edit-form', $attrib['id']);

        return $out;
    }

    /**
     * Template object for folder mounting form
     */
    public function folder_mount_form($attrib)
    {
        $sources = $this->rc->output->get_env('external_sources');

        if (empty($sources) || !is_array($sources)) {
            return '';
        }

        $attrib['name'] = 'folder-mount-form';
        if (empty($attrib['id'])) {
            $attrib['id'] = 'folder-mount-form';
        }

        // build form content
        $table        = new html_table(array('cols' => 2, 'class' => 'propform'));
        $input_name   = new html_inputfield(array('id' => 'folder-mount-name', 'name' => 'name', 'size' => 30));
        $input_driver = new html_radiobutton(array('name' => 'driver', 'size' => 30));

        $table->add('title', html::label('folder-mount-name', rcube::Q($this->plugin->gettext('name'))));
        $table->add(null, $input_name->show());

        foreach ($sources as $key => $source) {
            $id    = 'source-' . $key;
            $form  = new html_table(array('cols' => 2, 'class' => 'propform driverform'));

            foreach ((array) $source['form'] as $idx => $label) {
                $iid = $id . '-' . $idx;
                $type  = stripos($idx, 'pass') !== false ? 'html_passwordfield' : 'html_inputfield';
                $input = new $type(array('size' => 30));

                $form->add('title', html::label($iid, rcube::Q($label)));
                $form->add(null, $input->show('', array(
                        'id'   => $iid,
                        'name' => $key . '[' . $idx . ']'
                )));
            }

            $row = $input_driver->show(null, array('value' => $key))
                . html::img(array('src' => $source['image'], 'alt' => $key, 'title' => $source['name']))
                . html::div(null, html::span('name', rcube::Q($source['name']))
                    . html::br()
                    . html::span('description', rcube::Q($source['description']))
                    . $form->show()
                );

            $table->add(array('id' => $id, 'colspan' => 2, 'class' => 'source'), $row);
        }

        $out = $table->show() . $this->folder_auth_options(array('suffix' => '-form'));

        // add form tag around text field
        if (empty($attrib['form'])) {
            $out = $this->rc->output->form_tag($attrib, $out);
        }

        $this->plugin->add_label('foldermounting', 'foldermountnotice', 'foldermount',
            'save', 'cancel', 'folderauthtitle', 'authenticating'
        );
        $this->rc->output->add_gui_object('folder-mount-form', $attrib['id']);

        return $out;
    }

    /**
     * Template object for folder authentication options
     */
    public function folder_auth_options($attrib)
    {
        $checkbox = new html_checkbox(array(
            'name'  => 'store_passwords',
            'value' => '1',
            'id'    => 'auth-pass-checkbox' . $attrib['suffix'],
        ));

        return html::div('auth-options', $checkbox->show(). '&nbsp;'
            . html::label('auth-pass-checkbox' . $attrib['suffix'], $this->plugin->gettext('storepasswords'))
            . html::span('description', $this->plugin->gettext('storepasswordsdesc'))
        );
    }

    /**
     * Template object for file_edit form
     */
    public function file_edit_form($attrib)
    {
        $attrib['name'] = 'file-edit-form';
        if (empty($attrib['id'])) {
            $attrib['id'] = 'file-edit-form';
        }

        $input_name = new html_inputfield(array('id' => 'file-edit-name', 'name' => 'name', 'size' => 30));
        $table      = new html_table(array('cols' => 2, 'class' => 'propform'));

        $table->add('title', html::label('file-edit-name', rcube::Q($this->plugin->gettext('filename'))));
        $table->add(null, $input_name->show());

        $out = $table->show();

        // add form tag around text field
        if (empty($attrib['form'])) {
            $out = $this->rc->output->form_tag($attrib, $out);
        }

        $this->plugin->add_label('save', 'cancel', 'fileupdating', 'fileedit');
        $this->rc->output->add_gui_object('file-edit-form', $attrib['id']);

        return $out;
    }

    /**
     * Template object for file_create form
     */
    public function file_create_form($attrib)
    {
        $attrib['name'] = 'file-create-form';
        if (empty($attrib['id'])) {
            $attrib['id'] = 'file-create-form';
        }

        $input_name    = new html_inputfield(array('id' => 'file-create-name', 'name' => 'name', 'size' => 30));
        $select_parent = new html_select(array('id' => 'file-create-parent', 'name' => 'parent'));
        $select_type   = new html_select(array('id' => 'file-create-type', 'name' => 'type'));
        $table         = new html_table(array('cols' => 2, 'class' => 'propform'));

        // @TODO: get this list from Chwala API
        $types = array(
            'application/vnd.oasis.opendocument.text' => 'odt',
            'text/plain' => 'txt',
            'text/html'  => 'html',
        );
        foreach (array_keys($types) as $type) {
            list ($app, $label) = explode('/', $type);
            $label = preg_replace('/[^a-z]/', '', $label);
            $select_type->add($this->plugin->gettext('type.' . $label), $type);
        }

        $table->add('title', html::label('file-create-name', rcube::Q($this->plugin->gettext('filename'))));
        $table->add(null, $input_name->show());
        $table->add('title', html::label('file-create-type', rcube::Q($this->plugin->gettext('type'))));
        $table->add(null, $select_type->show());
        $table->add('title', html::label('folder-parent', rcube::Q($this->plugin->gettext('folderinside'))));
        $table->add(null, $select_parent->show());

        $out = $table->show();

        // add form tag around text field
        if (empty($attrib['form'])) {
            $out = $this->rc->output->form_tag($attrib, $out);
        }

        $this->plugin->add_label('create', 'cancel', 'filecreating', 'createfile');
        $this->rc->output->add_gui_object('file-create-form', $attrib['id']);
        $this->rc->output->set_env('file_extensions', $types);

        return $out;
    }

    /**
     * Template object for file search form in "From cloud" dialog
     */
    public function file_search_form($attrib)
    {
        $attrib['name'] = '_q';

        if (empty($attrib['id'])) {
            $attrib['id'] = 'filesearchbox';
        }
        if ($attrib['type'] == 'search' && !$this->rc->output->browser->khtml) {
            unset($attrib['type'], $attrib['results']);
        }

        $input_q = new html_inputfield($attrib);
        $out = $input_q->show();

        // add some labels to client
        $this->rc->output->add_label('searching');
        $this->rc->output->add_gui_object('filesearchbox', $attrib['id']);

        // add form tag around text field
        if (empty($attrib['form'])) {
            $out = $this->rc->output->form_tag(array(
                    'action'   => '?_task=files',
                    'name'     => "filesearchform",
                    'onsubmit' => rcmail_output::JS_OBJECT_NAME . ".command('files-search'); return false",
                ), $out);
        }

        return $out;
    }

    /**
     * Template object for files list
     */
    public function file_list($attrib)
    {
        // define list of cols to be displayed based on parameter or config
        if (empty($attrib['columns'])) {
            $list_cols     = $this->rc->config->get('kolab_files_list_cols');
            $dont_override = $this->rc->config->get('dont_override');
            $a_show_cols = is_array($list_cols) ? $list_cols : array('name');
            $this->rc->output->set_env('col_movable', !in_array('kolab_files_list_cols', (array)$dont_override));
        }
        else {
            $columns     = str_replace(array("'", '"'), '', $attrib['columns']);
            $a_show_cols = preg_split('/[\s,;]+/', $columns);
        }

        // make sure 'name' and 'options' column is present
        if (!in_array('name', $a_show_cols)) {
            array_unshift($a_show_cols, 'name');
        }
        if (!in_array('options', $a_show_cols)) {
            array_unshift($a_show_cols, 'options');
        }

        $attrib['columns'] = $a_show_cols;

        // save some variables for use in ajax list
        $_SESSION['kolab_files_list_attrib'] = $attrib;

        // For list in dialog(s) remove all option-like columns
        if ($this->rc->task != 'files') {
            $a_show_cols = array_intersect($a_show_cols, $this->sort_cols);
        }

        // set default sort col/order to session
        if (!isset($_SESSION['kolab_files_sort_col']))
            $_SESSION['kolab_files_sort_col'] = $this->rc->config->get('kolab_files_sort_col') ?: 'name';
        if (!isset($_SESSION['kolab_files_sort_order']))
            $_SESSION['kolab_files_sort_order'] = strtoupper($this->rc->config->get('kolab_files_sort_order') ?: 'asc');

        // set client env
        $this->rc->output->add_gui_object('filelist', $attrib['id']);
        $this->rc->output->set_env('sort_col', $_SESSION['kolab_files_sort_col']);
        $this->rc->output->set_env('sort_order', $_SESSION['kolab_files_sort_order']);
        $this->rc->output->set_env('coltypes', $a_show_cols);
        $this->rc->output->set_env('search_threads', $this->rc->config->get('kolab_files_search_threads'));

        $this->rc->output->include_script('list.js');

        // attach css rules for mimetype icons
        $this->plugin->include_stylesheet($this->url . '/skins/default/images/mimetypes/style.css');

        $thead = '';
        foreach ($this->file_list_head($attrib, $a_show_cols) as $cell) {
            $thead .= html::tag('th', array('class' => $cell['className'], 'id' => $cell['id']), $cell['html']);
        }

        return html::tag('table', $attrib,
            html::tag('thead', null, html::tag('tr', null, $thead)) . html::tag('tbody', null, ''),
            array('style', 'class', 'id', 'cellpadding', 'cellspacing', 'border', 'summary'));
    }

    /**
     * Creates <THEAD> for message list table
     */
    protected function file_list_head($attrib, $a_show_cols)
    {
        $skin_path = $_SESSION['skin_path'];
//        $image_tag = html::img(array('src' => "%s%s", 'alt' => "%s"));

        // check to see if we have some settings for sorting
        $sort_col   = $_SESSION['kolab_files_sort_col'];
        $sort_order = $_SESSION['kolab_files_sort_order'];

        $dont_override  = (array)$this->rc->config->get('dont_override');
        $disabled_sort  = in_array('message_sort_col', $dont_override);
        $disabled_order = in_array('message_sort_order', $dont_override);

        $this->rc->output->set_env('disabled_sort_col', $disabled_sort);
        $this->rc->output->set_env('disabled_sort_order', $disabled_order);

        // define sortable columns
        if ($disabled_sort)
            $a_sort_cols = $sort_col && !$disabled_order ? array($sort_col) : array();
        else
            $a_sort_cols = $this->sort_cols;

        if (!empty($attrib['optionsmenuicon'])) {
            $onclick = 'return ' . rcmail_output::JS_OBJECT_NAME . ".command('menu-open', 'filelistmenu', this, event)";
            $inner   = $this->rc->gettext('listoptions');

            if (is_string($attrib['optionsmenuicon']) && $attrib['optionsmenuicon'] != 'true') {
                $inner = html::img(array('src' => $skin_path . $attrib['optionsmenuicon'], 'alt' => $this->rc->gettext('listoptions')));
            }

            $list_menu = html::a(array(
                'href'     => '#list-options',
                'onclick'  => $onclick,
                'class'    => 'listmenu',
                'id'       => 'listmenulink',
                'title'    => $this->rc->gettext('listoptions'),
                'tabindex' => '0',
            ), $inner);
        }
        else {
            $list_menu = '';
        }

        $cells = array();

        foreach ($a_show_cols as $col) {
            // get column name
            switch ($col) {
/*
            case 'status':
                $col_name = '<span class="' . $col .'">&nbsp;</span>';
                break;
*/
            case 'options':
                $col_name = $list_menu;
                break;
            default:
                $col_name = rcube::Q($this->plugin->gettext($col));
            }

            // make sort links
            if (in_array($col, $a_sort_cols)) {
                $col_name = html::a(array(
                        'href'    => "#sort",
                        'onclick' => 'return ' . rcmail_output::JS_OBJECT_NAME . ".command('files-sort','$col',this)",
                        'title'   => $this->plugin->gettext('sortby')
                    ), $col_name);
            }
            else if ($col_name[0] != '<')
                $col_name = '<span class="' . $col .'">' . $col_name . '</span>';

            $sort_class = $col == $sort_col && !$disabled_order ? " sorted$sort_order" : '';
            $class_name = $col.$sort_class;

            // put it all together
            $cells[] = array('className' => $class_name, 'id' => "rcm$col", 'html' => $col_name);
        }

        return $cells;
    }

    /**
     * Update files list object
     */
    protected function file_list_update($prefs)
    {
        $attrib = $_SESSION['kolab_files_list_attrib'];

        if (!empty($prefs['kolab_files_list_cols'])) {
            $attrib['columns'] = $prefs['kolab_files_list_cols'];
            $_SESSION['kolab_files_list_attrib'] = $attrib;
        }

        $a_show_cols = $attrib['columns'];
        $head        = '';

        foreach ($this->file_list_head($attrib, $a_show_cols) as $cell) {
            $head .= html::tag('td', array('class' => $cell['className'], 'id' => $cell['id']), $cell['html']);
        }

        $head = html::tag('tr', null, $head);

        $this->rc->output->set_env('coltypes', $a_show_cols);
        $this->rc->output->command('files_list_update', $head);
    }

    /**
     * Template object for file info box
     */
    public function file_info_box($attrib)
    {
        // print_r($this->file_data, true);
        $table = new html_table(array('cols' => 2, 'class' => $attrib['class']));

        // file name
        $table->add('label', $this->plugin->gettext('name').':');
        $table->add('data filename', $this->file_data['name']);

        // file type
        // @TODO: human-readable type name
        $table->add('label', $this->plugin->gettext('type').':');
        $table->add('data filetype', $this->file_data['type']);

        // file size
        $table->add('label', $this->plugin->gettext('size').':');
        $table->add('data filesize', $this->rc->show_bytes($this->file_data['size']));

        // file modification time
        $table->add('label', $this->plugin->gettext('mtime').':');
        $table->add('data filemtime', $this->file_data['mtime']);

        // @TODO: for images: width, height, color depth, etc.
        // @TODO: for text files: count of characters, lines, words

        return $table->show();
    }

    /**
     * Template object for file preview frame
     */
    public function file_preview_frame($attrib)
    {
        if (empty($attrib['id'])) {
            $attrib['id'] = 'filepreviewframe';
        }

        if ($frame = $this->file_data['viewer']['frame']) {
            return $frame;
        }

        if ($href = $this->file_data['viewer']['href']) {
            // file href attribute must be an absolute URL (Bug #2063)
            if (!empty($href)) {
                if (!preg_match('|^https?://|', $href)) {
                    $href = $this->url . '/api/' . $href;
                }
            }
        }
        else {
            $token = $this->get_api_token();
            $href  = $this->url . '/api/?method=file_get'
                . '&file=' . urlencode($this->file_data['filename'])
                . '&token=' . urlencode($token);
        }

        $this->rc->output->add_gui_object('preview_frame', $attrib['id']);

        $attrib['allowfullscreen'] = true;
        $attrib['src']             = $href;
        $attrib['onload']          = 'kolab_files_frame_load(this)';

        return html::iframe($attrib);
    }

    /**
     * Template object for quota display
     */
    public function quota_display($attrib)
    {
        if (!$attrib['id']) {
            $attrib['id'] = 'rcmquotadisplay';
        }

        $quota_type = !empty($attrib['display']) ? $attrib['display'] : 'text';

        $this->rc->output->add_gui_object('quotadisplay', $attrib['id']);
        $this->rc->output->set_env('quota_type', $quota_type);

        // get quota
        $token   = $this->get_api_token();
        $request = $this->get_request(array('method' => 'quota'), $token);

        // send request to the API
        try {
            $response = $request->send();
            $status   = $response->getStatus();
            $body     = @json_decode($response->getBody(), true);

            if ($status == 200 && $body['status'] == 'OK') {
                $quota = $body['result'];
            }
            else {
                throw new Exception($body['reason']);
            }
        }
        catch (Exception $e) {
            rcube::raise_error($e, true, false);
            $quota = array('total' => 0, 'percent' => 0);
        }

        $quota = rcube_output::json_serialize($quota);

        $this->rc->output->add_script(rcmail_output::JS_OBJECT_NAME . ".files_set_quota($quota);", 'docready');

        return html::span($attrib, '');
    }

    /**
     * Get API token for current user session, authenticate if needed
     */
    public function get_api_token()
    {
        $token = $_SESSION['kolab_files_token'];
        $time  = $_SESSION['kolab_files_time'];

        if ($token && time() - $this->timeout < $time) {
            if (time() - $time <= $this->timeout / 2) {
                return $token;
            }
        }

        $request = $this->get_request(array('method' => 'ping'), $token);

        try {
            $url = $request->getUrl();

            // Send ping request
            if ($token) {
                $url->setQueryVariables(array('method' => 'ping'));
                $request->setUrl($url);
                $response = $request->send();
                $status   = $response->getStatus();

                if ($status == 200 && ($body = json_decode($response->getBody(), true))) {
                    if ($body['status'] == 'OK') {
                        $_SESSION['kolab_files_time']  = time();
                        return $token;
                    }
                }
            }

            // Go with authenticate request
            $url->setQueryVariables(array('method' => 'authenticate', 'version' => self::API_VERSION));
            $request->setUrl($url);
            $request->setAuth($this->rc->user->get_username(), $this->rc->decrypt($_SESSION['password']));
            $response = $request->send();
            $status   = $response->getStatus();

            if ($status == 200 && ($body = json_decode($response->getBody(), true))) {
                $token = $body['result']['token'];

                if ($token) {
                    $_SESSION['kolab_files_token'] = $token;
                    $_SESSION['kolab_files_time']  = time();
                    $_SESSION['kolab_files_caps']  = $body['result']['capabilities'];
                }
            }
            else {
                throw new Exception(sprintf("Authenticate error (Status: %d)", $status));
            }
        }
        catch (Exception $e) {
            rcube::raise_error($e, true, false);
        }

        return $token;
    }

    /**
     * Initialize HTTP_Request object
     */
    protected function get_request($get = null, $token = null)
    {
        $url = $this->url . '/api/';

        if (!$this->request) {
            $config = array(
                'store_body'       => true,
                'follow_redirects' => true,
            );

            $this->request = libkolab::http_request($url, 'GET', $config);
        }
        else {
            // cleanup
            try {
                $this->request->setBody('');
                $this->request->setUrl($url);
                $this->request->setMethod(HTTP_Request2::METHOD_GET);
            }
            catch (Exception $e) {
                rcube::raise_error($e, true, true);
            }
        }

        if ($token) {
            $this->request->setHeader('X-Session-Token', $token);
        }

        if (!empty($get)) {
            $url = $this->request->getUrl();
            $url->setQueryVariables($get);
            $this->request->setUrl($url);
        }

        // some HTTP server configurations require this header
        $this->request->setHeader('accept', "application/json,text/javascript,*/*");

        return $this->request;
    }

    /**
     * Handler for main files interface (Files task)
     */
    protected function action_index()
    {
        $this->plugin->add_label(
            'uploading', 'attaching', 'searching', 'uploadsizeerror',
            'filedeleting', 'filedeletenotice', 'filedeleteconfirm',
            'filemoving', 'filemovenotice', 'filemoveconfirm', 'filecopying', 'filecopynotice',
            'fileskip', 'fileskipall', 'fileoverwrite', 'fileoverwriteall'
        );

        $this->folder_list_env();

        $this->rc->output->add_label('uploadprogress', 'GB', 'MB', 'KB', 'B');
        $this->rc->output->set_pagetitle($this->plugin->gettext('files'));
        $this->rc->output->set_env('file_mimetypes', $this->get_mimetypes());
        $this->rc->output->set_env('files_quota', $_SESSION['kolab_files_caps']['QUOTA']);
        $this->rc->output->set_env('files_max_upload', $_SESSION['kolab_files_caps']['MAX_UPLOAD']);
        $this->rc->output->set_env('files_progress_name', $_SESSION['kolab_files_caps']['PROGRESS_NAME']);
        $this->rc->output->set_env('files_progress_time', $_SESSION['kolab_files_caps']['PROGRESS_TIME']);
        $this->rc->output->send('kolab_files.files');
    }

    /**
     * Handler for preferences save action
     */
    protected function action_prefs()
    {
        $dont_override = (array)$this->rc->config->get('dont_override');
        $prefs = array();
        $opts  = array(
            'kolab_files_sort_col' => true,
            'kolab_files_sort_order' => true,
            'kolab_files_list_cols' => false,
        );

        foreach ($opts as $o => $sess) {
            if (isset($_POST[$o]) && !in_array($o, $dont_override)) {
                $prefs[$o] = rcube_utils::get_input_value($o, rcube_utils::INPUT_POST);
                if ($sess) {
                    $_SESSION[$o] = $prefs[$o];
                }

                if ($o == 'kolab_files_list_cols') {
                    $update_list = true;
                }
            }
        }

        // save preference values
        if (!empty($prefs)) {
            $this->rc->user->save_prefs($prefs);
        }

        if (!empty($update_list)) {
            $this->file_list_update($prefs);
        }

        $this->rc->output->send();
    }

    /**
     * Handler for file open action
     */
    protected function action_open()
    {
        $file   = rcube_utils::get_input_value('file', rcube_utils::INPUT_GET);
        $viewer = intval($_GET['viewer']);

        // get file info
        $token   = $this->get_api_token();
        $request = $this->get_request(array(
            'method' => 'file_info',
            'file'   => $file,
            'viewer' => $viewer,
            ), $token);

        // send request to the API
        try {
            $response = $request->send();
            $status   = $response->getStatus();
            $body     = @json_decode($response->getBody(), true);

            if ($status == 200 && $body['status'] == 'OK') {
                $this->file_data = $body['result'];
            }
            else {
                throw new Exception($body['reason']);
            }
        }
        catch (Exception $e) {
            rcube::raise_error(array(
                'code' => 500, 'type' => 'php', 'line' => __LINE__, 'file' => __FILE__,
                'message' => $e->getMessage()),
                true, true);
        }

        $this->file_data['filename'] = $file;

        $this->plugin->add_label('filedeleteconfirm', 'filedeleting', 'filedeletenotice');

        // register template objects for dialogs (and main interface)
        $this->rc->output->add_handlers(array(
            'fileinfobox'      => array($this, 'file_info_box'),
            'filepreviewframe' => array($this, 'file_preview_frame'),
        ));

        // this one is for styling purpose
        $this->rc->output->set_env('extwin', true);
        $this->rc->output->set_env('file', $file);
        $this->rc->output->set_env('file_data', $this->file_data);
        $this->rc->output->set_pagetitle(rcube::Q($file));
        $this->rc->output->send('kolab_files.' . ($viewer & 4 ? 'docedit' : 'filepreview'));
    }

    /**
     * Handler for "save all attachments into cloud" action
     */
    protected function action_save_file()
    {
//        $source = rcube_utils::get_input_value('source', rcube_utils::INPUT_POST);
        $uid    = rcube_utils::get_input_value('uid', rcube_utils::INPUT_POST);
        $dest   = rcube_utils::get_input_value('dest', rcube_utils::INPUT_POST);
        $id     = rcube_utils::get_input_value('id', rcube_utils::INPUT_POST);
        $name   = rcube_utils::get_input_value('name', rcube_utils::INPUT_POST);

        $temp_dir = unslashify($this->rc->config->get('temp_dir'));
        $message  = new rcube_message($uid);
        $request  = $this->get_request();
        $url      = $request->getUrl();
        $files    = array();
        $errors   = array();
        $attachments = array();

        $request->setMethod(HTTP_Request2::METHOD_POST);
        $request->setHeader('X-Session-Token', $this->get_api_token());
        $url->setQueryVariables(array('method' => 'file_upload', 'folder' => $dest));
        $request->setUrl($url);

        foreach ($message->attachments as $attach_prop) {
            if (empty($id) || $id == $attach_prop->mime_id) {
                $filename = strlen($name) ? $name : rcmail_attachment_name($attach_prop, true);
                $attachments[$filename] = $attach_prop;
            }
        }

        // @TODO: handle error
        // @TODO: implement file upload using file URI instead of body upload

        foreach ($attachments as $attach_name => $attach_prop) {
            $path = tempnam($temp_dir, 'rcmAttmnt');

            // save attachment to file
            if ($fp = fopen($path, 'w+')) {
                $message->get_part_body($attach_prop->mime_id, false, 0, $fp);
            }
            else {
                $errors[] = true;
                rcube::raise_error(array(
                    'code' => 500, 'type' => 'php', 'line' => __LINE__, 'file' => __FILE__,
                    'message' => "Unable to save attachment into file $path"),
                    true, false);
                continue;
            }

            fclose($fp);

            // send request to the API
            try {
                $request->setBody('');
                $request->addUpload('file[]', $path, $attach_name, $attach_prop->mimetype);
                $response = $request->send();
                $status   = $response->getStatus();
                $body     = @json_decode($response->getBody(), true);

                if ($status == 200 && $body['status'] == 'OK') {
                    $files[] = $attach_name;
                }
                else {
                    throw new Exception($body['reason']);
                }
            }
            catch (Exception $e) {
                unlink($path);
                $errors[] = $e->getMessage();
                rcube::raise_error(array(
                    'code' => 500, 'type' => 'php', 'line' => __LINE__, 'file' => __FILE__,
                    'message' => $e->getMessage()),
                    true, false);
                continue;
            }

            // clean up
            unlink($path);
            $request->setBody('');
        }

        if ($count = count($files)) {
            $msg = $this->plugin->gettext(array('name' => 'saveallnotice', 'vars' => array('n' => $count)));
            $this->rc->output->show_message($msg, 'confirmation');
        }
        if ($count = count($errors)) {
            $msg = $this->plugin->gettext(array('name' => 'saveallerror', 'vars' => array('n' => $count)));
            $this->rc->output->show_message($msg, 'error');
        }

        // @TODO: update quota indicator, make this optional in case files aren't stored in IMAP

        $this->rc->output->send();
    }

    /**
     * Handler for "add attachments from the cloud" action
     */
    protected function action_attach_file()
    {
        $files      = rcube_utils::get_input_value('files', rcube_utils::INPUT_POST);
        $uploadid   = rcube_utils::get_input_value('uploadid', rcube_utils::INPUT_POST);
        $COMPOSE_ID = rcube_utils::get_input_value('id', rcube_utils::INPUT_POST);
        $COMPOSE    = null;
        $errors     = array();

        if ($COMPOSE_ID && $_SESSION['compose_data_'.$COMPOSE_ID]) {
            $COMPOSE =& $_SESSION['compose_data_'.$COMPOSE_ID];
        }

        if (!$COMPOSE) {
            die("Invalid session var!");
        }

        // attachment upload action
        if (!is_array($COMPOSE['attachments'])) {
            $COMPOSE['attachments'] = array();
        }

        // clear all stored output properties (like scripts and env vars)
        $this->rc->output->reset();

        $temp_dir = unslashify($this->rc->config->get('temp_dir'));
        $request  = $this->get_request();
        $url      = $request->getUrl();

        // Use observer object to store HTTP response into a file
        require_once $this->plugin->home . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'kolab_files_observer.php';
        $observer = new kolab_files_observer();

        $request->setHeader('X-Session-Token', $this->get_api_token());

        // download files from the API and attach them
        foreach ($files as $file) {
            // decode filename
            $file = urldecode($file);

            // get file information
            try {
                $url->setQueryVariables(array('method' => 'file_info', 'file' => $file));
                $request->setUrl($url);
                $response = $request->send();
                $status   = $response->getStatus();
                $body     = @json_decode($response->getBody(), true);

                if ($status == 200 && $body['status'] == 'OK') {
                    $file_params = $body['result'];
                }
                else {
                    throw new Exception($body['reason']);
                }
            }
            catch (Exception $e) {
                $errors[] = $e->getMessage();
                rcube::raise_error(array(
                    'code' => 500, 'type' => 'php', 'line' => __LINE__, 'file' => __FILE__,
                    'message' => $e->getMessage()),
                    true, false);
                continue;
            }

            // set location of downloaded file
            $path = tempnam($temp_dir, 'rcmAttmnt');
            $observer->set_file($path);

            // download file
            try {
                $url->setQueryVariables(array('method' => 'file_get', 'file' => $file));
                $request->setUrl($url);
                $request->attach($observer);
                $response = $request->send();
                $status   = $response->getStatus();
                $response->getBody(); // returns nothing
                $request->detach($observer);

                if ($status != 200 || !file_exists($path)) {
                    throw new Exception("Unable to save file");
                }
            }
            catch (Exception $e) {
                $errors[] = $e->getMessage();
                rcube::raise_error(array(
                    'code' => 500, 'type' => 'php', 'line' => __LINE__, 'file' => __FILE__,
                    'message' => $e->getMessage()),
                    true, false);
                continue;
            }

            $attachment = array(
                'path' => $path,
                'size' => $file_params['size'],
                'name' => $file_params['name'],
                'mimetype' => $file_params['type'],
                'group' => $COMPOSE_ID,
            );

            $attachment = $this->rc->plugins->exec_hook('attachment_save', $attachment);

            if ($attachment['status'] && !$attachment['abort']) {
                $id = $attachment['id'];

                // store new attachment in session
                unset($attachment['data'], $attachment['status'], $attachment['abort']);
                $COMPOSE['attachments'][$id] = $attachment;

                if (($icon = $COMPOSE['deleteicon']) && is_file($icon)) {
                    $button = html::img(array(
                        'src' => $icon,
                        'alt' => $this->rc->gettext('delete')
                    ));
                }
                else {
                    $button = rcube::Q($this->rc->gettext('delete'));
                }

                $content = html::a(array(
                    'href' => "#delete",
                    'onclick' => sprintf("return %s.command('remove-attachment','rcmfile%s', this)", rcmail_output::JS_OBJECT_NAME, $id),
                    'title' => $this->rc->gettext('delete'),
                    'class' => 'delete',
                ), $button);

                $content .= rcube::Q($attachment['name']);

                $this->rc->output->command('add2attachment_list', "rcmfile$id", array(
                    'html'      => $content,
                    'name'      => $attachment['name'],
                    'mimetype'  => $attachment['mimetype'],
                    'classname' => rcmail_filetype2classname($attachment['mimetype'], $attachment['name']),
                    'complete'  => true), $uploadid);
            }
            else if ($attachment['error']) {
                $errors[] = $attachment['error'];
            }
            else {
                $errors[] = $this->plugin->gettext('attacherror');
            }
        }

        if (!empty($errors)) {
            $this->rc->output->command('display_message', $this->plugin->gettext('attacherror'), 'error');
            $this->rc->output->command('remove_from_attachment_list', $uploadid);
        }

        // send html page with JS calls as response
        $this->rc->output->command('auto_save_start', false);
        $this->rc->output->send();
    }

    /**
     * Returns mimetypes supported by File API viewers
     */
    protected function get_mimetypes()
    {
        $token   = $this->get_api_token();
        $request = $this->get_request(array('method' => 'mimetypes'), $token);

        // send request to the API
        try {
            $response = $request->send();
            $status   = $response->getStatus();
            $body     = @json_decode($response->getBody(), true);

            if ($status == 200 && $body['status'] == 'OK') {
                $mimetypes = $body['result'];
            }
            else {
                throw new Exception($body['reason']);
            }
        }
        catch (Exception $e) {
            rcube::raise_error(array(
                'code' => 500, 'type' => 'php', 'line' => __LINE__, 'file' => __FILE__,
                'message' => $e->getMessage()),
                true, false);
        }

        return $mimetypes;
    }

    /**
     * Get list of available external storage drivers
     */
    protected function get_external_storage_drivers()
    {
        // first get configured sources from Chwala
        $token   = $this->get_api_token();
        $request = $this->get_request(array('method' => 'folder_types'), $token);

        // send request to the API
        try {
            $response = $request->send();
            $status   = $response->getStatus();
            $body     = @json_decode($response->getBody(), true);

            if ($status == 200 && $body['status'] == 'OK') {
                $sources = $body['result'];
            }
            else {
                throw new Exception($body['reason']);
            }
        }
        catch (Exception $e) {
            rcube::raise_error($e, true, false);
            return;
        }

        $this->rc->output->set_env('external_sources', $sources);
    }

    /**
     * Registers translation labels for folder lists in UI
     */
    protected function folder_list_env()
    {
        // folder list and actions
        $this->plugin->add_label(
            'folderdeleting', 'folderdeleteconfirm', 'folderdeletenotice',
            'collection_audio', 'collection_video', 'collection_image', 'collection_document',
            'additionalfolders', 'listpermanent'
        );
        $this->rc->output->add_label('foldersubscribing', 'foldersubscribed',
            'folderunsubscribing', 'folderunsubscribed', 'searching'
        );

        $this->rc->output->set_env('files_caps', $_SESSION['kolab_files_caps']);
    }
}
