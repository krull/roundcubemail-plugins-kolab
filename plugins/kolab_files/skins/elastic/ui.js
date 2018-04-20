function kolab_files_enable_command(p)
{
    if (p.command == 'files-save') {
        var toolbar = $('#toolbar-menu');
        $('a.button.edit', toolbar).parent().hide();
        $('a.button.save', toolbar).show().parent().show();
    }
};

function kolab_files_listoptions(type)
{
    var content = $('#' + type + 'listoptions'),
        width = content.width() + 25,
        dialog = content.clone(),
        title = rcmail.gettext('kolab_files.arialabel' + (type == 'sessions' ? 'sessions' : '') + 'listoptions'),
        close_func = function() { rcmail[type + 'list'].focus(); },
        save_func = function(e) {
            if (rcube_event.is_keyboard(e.originalEvent)) {
                $('#' + type + 'listmenu-link').focus();
            }

            var col = $('select[name="sort_col"]', dialog).val(),
                ord = $('select[name="sort_ord"]', dialog).val();

            kolab_files_set_list_options([], col, ord, type);
            close_func();
            return true;
        };

    // set form values
    $('select[name="sort_col"]', dialog).val(rcmail.env[type + '_sort_col'] || 'name');
    $('select[name="sort_ord"]', dialog).val(rcmail.env[type + '_sort_order'] == 'DESC' ? 'DESC' : 'ASC');

    dialog = rcmail.simple_dialog(dialog, title, save_func, {
        cancel_func: close_func,
        closeOnEscape: true,
        minWidth: 400,
        width: width
    });
};


if (rcmail.env.action == 'open' || rcmail.env.action == 'edit') {
    rcmail.addEventListener('enable-command', kolab_files_enable_command);

    if ($('#exportmenu').length)
        rcmail.gui_object('exportmenu', 'exportmenu');

    // center and scale the image in preview frame
    if (rcmail.env.mimetype.startsWith('image/')) {
        $('#fileframe').on('load', function() {
            var css = 'img { max-width:100%; max-height:100%; } ' // scale
                + 'body { display:flex; align-items:center; justify-content:center; height:100%; margin:0; }'; // align

            $(this).contents().find('head').append('<style type="text/css">'+ css + '</style>');
        });
    }
}
else {
    rcmail.addEventListener('files-folder-select', function(p) {
        var is_sess = p.folder == 'folder-collection-sessions';
        $('#fileslistmenu-link, #layout > .content > .header > a.toggleselect, #layout > .content > .header > .searchbar')[is_sess ? 'hide' : 'show']();
        $('#sessionslistmenu-link')[is_sess ? 'removeClass' : 'addClass']('hidden');

        // set list header title for mobile
        // $('#layout > .content > .header > .header-title').text($('#files-folder-list li.selected a.name:first').text());
    });
}

$(document).ready(function() {

    $('#toolbar-menu a.button.save').parent().hide();

    if ($('#dragfilemenu').length) {
        rcmail.gui_object('file_dragmenu', 'dragfilemenu');
    }

    if ($('#filesearchmenu').length) {
        rcmail.gui_object('file_searchmenu', 'filesearchmenu');
    }
});

kolab_files_upload_input('#filesuploadform');