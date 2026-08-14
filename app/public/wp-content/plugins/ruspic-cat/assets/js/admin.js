jQuery(function($){
  $(document).on('click','.ruspic-media-select',function(e){e.preventDefault();const btn=$(this),target=btn.data('target'),frame=wp.media({title:'Выберите изображение',button:{text:'Использовать'},multiple:false,library:{type:'image'}});frame.on('select',function(){const a=frame.state().get('selection').first().toJSON();$('input[name="'+target+'"]').val(a.id);btn.siblings('.ruspic-media-preview').html('<img src="'+(a.sizes?.thumbnail?.url || a.url)+'" alt="">');});frame.open();});
});
