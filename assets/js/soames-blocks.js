(function () {
  var registerBlockType = wp.blocks.registerBlockType;
  var el = wp.element.createElement;
  var Fragment = wp.element.Fragment;
  var TextControl = wp.components.TextControl;
  var TextareaControl = wp.components.TextareaControl;
  var SelectControl = wp.components.SelectControl;
  var PanelBody = wp.components.PanelBody;
  var Button = wp.components.Button;
  var InspectorControls = wp.blockEditor.InspectorControls;
  var MediaUpload = (wp.blockEditor && wp.blockEditor.MediaUpload) || (wp.editor && wp.editor.MediaUpload);
  var MediaUploadCheck = (wp.blockEditor && wp.blockEditor.MediaUploadCheck) || (wp.editor && wp.editor.MediaUploadCheck);
  // Block API v3 (ORBI-28): every block's edit() must apply useBlockProps to its
  // wrapper element, or the iframed editor warns/breaks.
  var useBlockProps = wp.blockEditor.useBlockProps;

  var CATEGORY = 'soames';

  // Shared grouped-items editor (ORBI-20) for blocks whose items are
  // { image, label, link, css }. Stores an `items` array; migrates legacy
  // parallel comma fields (images/labels/links/css) to rows on first edit.
  // opts: { title, help, itemLabel, addLabel }
  function groupedItemsEdit(props, opts) {
    var items = Array.isArray(props.attributes.items) ? props.attributes.items : [];
    if (items.length === 0 && (props.attributes.images || '').trim().length) {
      var imgs = props.attributes.images.split(',');
      var lbls = (props.attributes.labels || '').split(',');
      var lnks = (props.attributes.links || '').split(',');
      var clss = (props.attributes.css || '').split(',');
      items = imgs.map(function (img, i) {
        return {
          image: (img || '').trim(),
          label: (lbls[i] || '').trim(),
          link:  (lnks[i] || '').trim(),
          css:   (clss[i] || '').trim()
        };
      });
    }

    // Write items and clear the legacy fields so the server render uses items.
    function commit(next) {
      props.setAttributes({ items: next, images: '', labels: '', links: '', css: '' });
    }
    function updateField(i, field, value) {
      commit(items.map(function (it, j) {
        if (j !== i) return it;
        var copy = Object.assign({}, it);
        copy[field] = value;
        return copy;
      }));
    }
    function addItem() { commit(items.concat([{ image: '', label: '', link: '', css: '' }])); }
    function removeItem(i) { commit(items.filter(function (_, j) { return j !== i; })); }
    function move(i, dir) {
      var j = i + dir;
      if (j < 0 || j >= items.length) return;
      var next = items.slice();
      var tmp = next[i]; next[i] = next[j]; next[j] = tmp;
      commit(next);
    }

    var itemLabel = opts.itemLabel || 'Item';
    var rows = items.map(function (it, i) {
      return el('div', {
        key: i,
        style: { border: '1px solid #e0e0e0', borderRadius: '4px', padding: '12px', marginBottom: '8px', background: '#fff' }
      },
        el('div', { style: { display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '6px' } },
          el('strong', {}, itemLabel + ' ' + (i + 1)),
          el('div', {},
            el(Button, { isSmall: true, icon: 'arrow-up-alt2',   label: 'Move up',   disabled: i === 0,                onClick: function () { move(i, -1); } }),
            el(Button, { isSmall: true, icon: 'arrow-down-alt2', label: 'Move down', disabled: i === items.length - 1, onClick: function () { move(i, 1); } }),
            el(Button, { isSmall: true, isDestructive: true, icon: 'trash', label: 'Remove', onClick: function () { removeItem(i); } })
          )
        ),
        el('div', { style: { marginBottom: '8px' } },
          el(MediaUploadCheck, {},
            el(MediaUpload, {
              allowedTypes: ['image'],
              onSelect: function (media) { updateField(i, 'image', media.url); },
              render: function (o) {
                return el('div', { style: { display: 'flex', alignItems: 'center', gap: '8px', marginBottom: '4px' } },
                  it.image
                    ? el('img', { src: it.image, alt: '', style: { height: '40px', width: '40px', objectFit: 'contain', border: '1px solid #ddd', borderRadius: '4px' } })
                    : null,
                  el(Button, { isSecondary: true, onClick: o.open }, it.image ? 'Replace image' : 'Select image'),
                  it.image
                    ? el(Button, { isLink: true, isDestructive: true, onClick: function () { updateField(i, 'image', ''); } }, 'Clear')
                    : null
                );
              }
            })
          ),
          el(TextControl, { label: 'Image URL', value: it.image || '', onChange: function (v) { updateField(i, 'image', v); } })
        ),
        el(TextControl, { label: 'Label',     value: it.label || '', onChange: function (v) { updateField(i, 'label', v); } }),
        el(TextControl, { label: 'Link',      value: it.link  || '', onChange: function (v) { updateField(i, 'link',  v); } }),
        el(TextControl, { label: 'CSS class (optional)', value: it.css || '', onChange: function (v) { updateField(i, 'css', v); } })
      );
    });

    return el('div', useBlockProps({ style: { padding: '12px', border: '1px solid #ddd' } }),
      el('strong', {}, opts.title),
      el('p', { style: { fontSize: '12px', color: '#666', margin: '4px 0 10px' } }, opts.help),
      rows.length ? rows : el('p', { style: { color: '#888', fontStyle: 'italic' } }, 'Nothing yet — add one below.'),
      el(Button, { isPrimary: true, icon: 'plus', onClick: addItem }, opts.addLabel || 'Add item')
    );
  }

  // Text-list repeater (ORBI-42): one HTML chunk per list item. Each item is
  // { content }. The theme wraps items in <ul><li>… and applies bullets +
  // top/bottom spacing via CSS — no manual <ul>/<li> or <br><br> needed. A legacy
  // single `content` string is migrated into one item on first edit.
  function textItemsEdit(props) {
    var items = Array.isArray(props.attributes.items) ? props.attributes.items : [];
    if (items.length === 0 && (props.attributes.content || '').trim().length) {
      items = [{ content: props.attributes.content }];
    }

    // Write items and clear the legacy field so the server render uses items.
    function commit(next) { props.setAttributes({ items: next, content: '' }); }
    function updateContent(i, value) {
      commit(items.map(function (it, j) {
        if (j !== i) return it;
        var copy = Object.assign({}, it);
        copy.content = value;
        return copy;
      }));
    }
    function addItem() { commit(items.concat([{ content: '' }])); }
    function removeItem(i) { commit(items.filter(function (_, j) { return j !== i; })); }
    function move(i, dir) {
      var j = i + dir;
      if (j < 0 || j >= items.length) return;
      var next = items.slice();
      var tmp = next[i]; next[i] = next[j]; next[j] = tmp;
      commit(next);
    }

    var rows = items.map(function (it, i) {
      return el('div', {
        key: i,
        style: { border: '1px solid #e0e0e0', borderRadius: '4px', padding: '12px', marginBottom: '8px', background: '#fff' }
      },
        el('div', { style: { display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '6px' } },
          el('strong', {}, 'Section ' + (i + 1)),
          el('div', {},
            el(Button, { isSmall: true, icon: 'arrow-up-alt2',   label: 'Move up',   disabled: i === 0,                onClick: function () { move(i, -1); } }),
            el(Button, { isSmall: true, icon: 'arrow-down-alt2', label: 'Move down', disabled: i === items.length - 1, onClick: function () { move(i, 1); } }),
            el(Button, { isSmall: true, isDestructive: true, icon: 'trash', label: 'Remove', onClick: function () { removeItem(i); } })
          )
        ),
        el(TextareaControl, {
          label: 'Content (HTML)',
          value: it.content || '',
          rows: 6,
          onChange: function (v) { updateContent(i, v); }
        })
      );
    });

    return el('div', useBlockProps({ style: { padding: '12px', border: '1px solid #ddd' } }),
      el('strong', {}, 'Soames Text List'),
      el('p', { style: { fontSize: '12px', color: '#666', margin: '4px 0 10px' } }, 'Add a section per list item. Each takes HTML; bullets and top/bottom spacing are applied automatically.'),
      rows.length ? rows : el('p', { style: { color: '#888', fontStyle: 'italic' } }, 'Nothing yet — add one below.'),
      el(Button, { isPrimary: true, icon: 'plus', onClick: addItem }, 'Add section')
    );
  }

  // soames/title-bar
  registerBlockType('soames/title-bar', {
    apiVersion: 3,
    title: 'Soames Title Bar',
    icon: 'heading',
    category: CATEGORY,
    attributes: {
      title: { type: 'string', default: '' }
    },
    edit: function (props) {
      return el('div', useBlockProps({ style: { padding: '12px', border: '1px solid #ddd' } }),
        el('strong', {}, 'Soames Title Bar'),
        el(TextControl, {
          label: 'Title',
          value: props.attributes.title,
          onChange: function (v) { props.setAttributes({ title: v }); }
        })
      );
    },
    save: function () { return null; }
  });

  // soames/title-bar-lg
  registerBlockType('soames/title-bar-lg', {
    apiVersion: 3,
    title: 'Soames Title Bar (Large)',
    icon: 'cover-image',
    category: CATEGORY,
    attributes: {
      title:      { type: 'string', default: '' },
      subtitle:   { type: 'string', default: '' },
      background: { type: 'string', default: '' }
    },
    edit: function (props) {
      return el('div', useBlockProps({ style: { padding: '12px', border: '1px solid #ddd' } }),
        el('strong', {}, 'Soames Title Bar (Large)'),
        el(TextControl, { label: 'Title',          value: props.attributes.title,      onChange: function (v) { props.setAttributes({ title: v }); } }),
        el(TextControl, { label: 'Subtitle',       value: props.attributes.subtitle,   onChange: function (v) { props.setAttributes({ subtitle: v }); } }),
        el(TextControl, { label: 'Background URL', value: props.attributes.background, onChange: function (v) { props.setAttributes({ background: v }); } })
      );
    },
    save: function () { return null; }
  });

  // soames/icon-list — grouped repeater (ORBI-20). Each icon keeps its
  // image/label/link/css together; serialized as an `items` array.
  registerBlockType('soames/icon-list', {
    apiVersion: 3,
    title: 'Soames Icon List',
    icon: 'list-view',
    category: CATEGORY,
    attributes: {
      items:  { type: 'array',  default: [] },
      // legacy comma fields, kept so pre-ORBI-20 blocks still read/migrate
      images: { type: 'string', default: '' },
      labels: { type: 'string', default: '' },
      links:  { type: 'string', default: '' },
      css:    { type: 'string', default: '' }
    },
    edit: function (props) {
      return groupedItemsEdit(props, {
        title: 'Soames Icon List',
        help: 'Each icon groups its image, label, and link together.',
        itemLabel: 'Icon',
        addLabel: 'Add icon'
      });
    },
    save: function () { return null; }
  });

  // soames/feature
  registerBlockType('soames/feature', {
    apiVersion: 3,
    title: 'Soames Feature',
    icon: 'align-left',
    category: CATEGORY,
    attributes: {
      content: { type: 'string', default: '' },
      image:   { type: 'string', default: '' },
      title:   { type: 'string', default: '' },
      css:     { type: 'string', default: '' }
    },
    edit: function (props) {
      var image = props.attributes.image || '';
      return el('div', useBlockProps({ style: { padding: '12px', border: '1px solid #ddd' } }),
        el('strong', {}, 'Soames Feature'),
        el(TextControl, { label: 'Title', value: props.attributes.title, onChange: function (v) { props.setAttributes({ title: v }); } }),
        el(TextareaControl, {
          label: 'Content (HTML)',
          value: props.attributes.content,
          rows: 8,
          onChange: function (v) { props.setAttributes({ content: v }); }
        }),
        el('div', { style: { marginBottom: '8px' } },
          el(MediaUploadCheck, {},
            el(MediaUpload, {
              allowedTypes: ['image'],
              onSelect: function (media) { props.setAttributes({ image: media.url }); },
              render: function (o) {
                return el('div', { style: { display: 'flex', alignItems: 'center', gap: '8px', marginBottom: '4px' } },
                  image
                    ? el('img', { src: image, alt: '', style: { height: '40px', width: '40px', objectFit: 'contain', border: '1px solid #ddd', borderRadius: '4px' } })
                    : null,
                  el(Button, { isSecondary: true, onClick: o.open }, image ? 'Replace image' : 'Select image'),
                  image
                    ? el(Button, { isLink: true, isDestructive: true, onClick: function () { props.setAttributes({ image: '' }); } }, 'Clear')
                    : null
                );
              }
            })
          ),
          el(TextControl, { label: 'Image URL', value: image, onChange: function (v) { props.setAttributes({ image: v }); } })
        ),
        el(TextControl, { label: 'CSS Class', value: props.attributes.css, onChange: function (v) { props.setAttributes({ css: v }); } })
      );
    },
    save: function () { return null; }
  });

  // soames/gallery-menu — grouped repeater (ORBI-20), same shape as icon-list.
  registerBlockType('soames/gallery-menu', {
    apiVersion: 3,
    title: 'Soames Gallery Menu',
    icon: 'grid-view',
    category: CATEGORY,
    attributes: {
      items:  { type: 'array',  default: [] },
      // ORBI-44: 'standard' (3 per row) or 'compact' (4 per row)
      layout: { type: 'string', default: 'standard' },
      // legacy comma fields, kept so pre-ORBI-20 blocks still read/migrate
      images: { type: 'string', default: '' },
      labels: { type: 'string', default: '' },
      links:  { type: 'string', default: '' },
      css:    { type: 'string', default: '' }
    },
    edit: function (props) {
      var layout = props.attributes.layout || 'standard';
      return el(Fragment, {},
        el(InspectorControls, {},
          el(PanelBody, { title: 'Layout', initialOpen: true },
            el(SelectControl, {
              label: 'View',
              value: layout,
              options: [
                { label: 'Standard (3 per row)', value: 'standard' },
                { label: 'Compact (4 per row)',  value: 'compact' }
              ],
              onChange: function (v) { props.setAttributes({ layout: v }); }
            })
          )
        ),
        groupedItemsEdit(props, {
          title: 'Soames Gallery Menu',
          help: 'Each gallery item groups its image, label, and link together.',
          itemLabel: 'Item',
          addLabel: 'Add item'
        })
      );
    },
    save: function () { return null; }
  });

  // soames/video
  registerBlockType('soames/video', {
    apiVersion: 3,
    title: 'Soames Video',
    icon: 'video-alt3',
    category: CATEGORY,
    attributes: {
      link:  { type: 'string', default: '' },
      title: { type: 'string', default: '' }
    },
    edit: function (props) {
      return el('div', useBlockProps({ style: { padding: '12px', border: '1px solid #ddd' } }),
        el('strong', {}, 'Soames Video'),
        el(TextControl, { label: 'Video URL', value: props.attributes.link,  onChange: function (v) { props.setAttributes({ link: v }); } }),
        el(TextControl, { label: 'Title',     value: props.attributes.title, onChange: function (v) { props.setAttributes({ title: v }); } })
      );
    },
    save: function () { return null; }
  });

  // soames/soundcloud
  registerBlockType('soames/soundcloud', {
    apiVersion: 3,
    title: 'Soames SoundCloud',
    icon: 'format-audio',
    category: CATEGORY,
    attributes: {
      bandName:   { type: 'string', default: '' },
      siteLink:   { type: 'string', default: '' },
      playlistId: { type: 'string', default: '' },
      albumLink:  { type: 'string', default: '' },
      albumName:  { type: 'string', default: '' }
    },
    edit: function (props) {
      return el('div', useBlockProps({ style: { padding: '12px', border: '1px solid #ddd' } }),
        el('strong', {}, 'Soames SoundCloud'),
        el(TextControl, { label: 'Band Name',   value: props.attributes.bandName,   onChange: function (v) { props.setAttributes({ bandName: v }); } }),
        el(TextControl, { label: 'Site Link',   value: props.attributes.siteLink,   onChange: function (v) { props.setAttributes({ siteLink: v }); } }),
        el(TextControl, { label: 'Playlist ID', value: props.attributes.playlistId, onChange: function (v) { props.setAttributes({ playlistId: v }); } }),
        el(TextControl, { label: 'Album Link',  value: props.attributes.albumLink,  onChange: function (v) { props.setAttributes({ albumLink: v }); } }),
        el(TextControl, { label: 'Album Name',  value: props.attributes.albumName,  onChange: function (v) { props.setAttributes({ albumName: v }); } })
      );
    },
    save: function () { return null; }
  });

  // soames/text-list — grouped repeater (ORBI-42). Each list item holds an HTML
  // chunk; serialized as an `items` array. Legacy single `content` string kept so
  // pre-ORBI-42 blocks still read/migrate.
  registerBlockType('soames/text-list', {
    apiVersion: 3,
    title: 'Soames Text List',
    icon: 'editor-ul',
    category: CATEGORY,
    attributes: {
      items:   { type: 'array',  default: [] },
      content: { type: 'string', default: '' }
    },
    edit: function (props) { return textItemsEdit(props); },
    save: function () { return null; }
  });

})();
