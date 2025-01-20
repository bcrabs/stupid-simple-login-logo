/* global wp */
(function($) {
    'use strict';

    // Ensure required data is available
    if (typeof ssllData === 'undefined') {
        console.error('SSLL: Required data is missing');
        return;
    }

    // Cache DOM elements
    var $preview = $('#logo_preview');
    var $logoUrl = $('#logo_url');
    var $uploadButton = $('#upload_logo_button');
    var $removeButton = $('#remove_logo_button');
    var $saveButton = $('#submit');
    var mediaUploader = null;
    
    function updateImagePreview(url) {
        if (!url) {
            $preview.length && $preview.remove();
            $removeButton.hide();
            $saveButton.hide();
            $uploadButton.text(ssllData.translations.selectLogo);
            return;
        }
        
        if (!$preview.length) {
            $preview = $('<img>', {
                id: 'logo_preview',
                alt: 'Login Logo Preview',
                style: 'max-width: 320px; height: auto; margin: 2em 0;'
            }).prependTo('.ssll-logo-preview');
        }
        
        $preview.attr('src', url);
        $removeButton.show();
        $saveButton.show();
        $uploadButton.text(ssllData.translations.changeLogo);
    }

    // Handle logo selection
    $uploadButton.on('click', function(e) {
        e.preventDefault();
        
        if (mediaUploader) {
            mediaUploader.open();
            return;
        }
        
        mediaUploader = wp.media({
            title: ssllData.frame_title,
            button: {
                text: ssllData.frame_button
            },
            multiple: false,
            library: {
                type: 'image'
            }
        });
        
        mediaUploader.on('select', function() {
            var attachment = mediaUploader.state().get('selection').first().toJSON();
            
            // Validate file type
            if (!attachment.mime.match(/^image\/(jpeg|png)$/)) {
                alert(ssllData.translations.invalidType);
                return;
            }
            
            // Validate file size
            if (attachment.size > 5242880) {
                alert(ssllData.translations.fileTooBig);
                return;
            }
            
            // Update form
            $logoUrl.val(attachment.url);
            updateImagePreview(attachment.url);
        });
        
        mediaUploader.open();
    });
    
    // Handle logo removal confirmation
    $removeButton.length && $removeButton.on('click', function(e) {
        if (!confirm($(this).data('confirm'))) {
            e.preventDefault();
        }
    });
})(jQuery);