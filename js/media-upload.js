/* global wp */
jQuery(document).ready(function($) {
    // Initialize media uploader
    let mediaUploader;
    
    // Handle logo upload
    $('#upload_logo_button').on('click', function(e) {
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
            const attachment = mediaUploader.state().get('selection').first().toJSON();
            
            // Validate file type
            if (!ssllData.allowedTypes.includes(attachment.mime)) {
                alert(ssllData.translations.invalidType);
                return;
            }
            
            // Validate file size
            if (attachment.filesize > ssllData.maxFileSize) {
                alert(ssllData.translations.fileTooBig);
                return;
            }
            
            // Update preview and hidden input
            $('#logo_preview').remove();
            $('form div').first().append(
                $('<img>', {
                    id: 'logo_preview',
                    src: attachment.url,
                    alt: 'Current login logo',
                    style: 'max-width: 320px; height: auto;'
                })
            );
            $('#logo_url').val(attachment.url);
            
            // Update button text
            $('#upload_logo_button').text(ssllData.translations.changeLogo);
            
            // Show remove button if not already visible
            if ($('#remove_logo_button').length === 0) {
                $('form p').first().append(
                    $('<button>', {
                        type: 'button',
                        class: 'button',
                        id: 'remove_logo_button',
                        text: ssllData.translations.removeLogo
                    })
                );
            }
        });
        
        mediaUploader.open();
    });
    
    // Handle logo removal
    $(document).on('click', '#remove_logo_button', function(e) {
        e.preventDefault();
        
        if (!confirm(ssllData.translations.removeConfirm)) {
            return;
        }
        
        // Create form and submit
        const form = $('<form>', {
            method: 'post',
            action: ssllData.adminPostUrl
        });
        
        form.append($('<input>', {
            type: 'hidden',
            name: 'action',
            value: 'ssll_remove_logo'
        }));
        
        form.append($('<input>', {
            type: 'hidden',
            name: 'nonce',
            value: ssllData.nonce
        }));
        
        $('body').append(form);
        form.submit();
    });
});