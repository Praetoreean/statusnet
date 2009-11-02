// add a notice encoded as JSON into the current timeline
//
// TODO: i18n

RealtimeUpdate = {
     _userid: 0,
     _replyurl: '',
     _favorurl: '',
     _deleteurl: '',

     init: function(userid, replyurl, favorurl, deleteurl)
     {
        RealtimeUpdate._userid = userid;
        RealtimeUpdate._replyurl = replyurl;
        RealtimeUpdate._favorurl = favorurl;
        RealtimeUpdate._deleteurl = deleteurl;

        $(window).blur(function() {
          $('#notices_primary .notice').css({
            'border-top-color':$('#notices_primary .notice:last').css('border-top-color'),
            'border-top-style':'dotted'
          });

          $('#notices_primary .notice:first').css({
            'border-top-color':'#AAAAAA',
            'border-top-style':'solid'
          });
        });
     },

     receive: function(data)
     {
          id = data.id;

          // Don't add it if it already exists
          //
          if ($("#notice-"+id).length > 0) {
               return;
          }

          var noticeItem = RealtimeUpdate.makeNoticeItem(data);
          $("#notices_primary .notices").prepend(noticeItem);
          $("#notices_primary .notice:first").css({display:"none"});
          $("#notices_primary .notice:first").fadeIn(1000);
          NoticeReply();
     },

     makeNoticeItem: function(data)
     {
          user = data['user'];
          html = data['html'].replace(/&amp;/g,'&').replace(/&lt;/g,'<').replace(/&gt;/g,'>').replace(/&quot;/g,'"');
          source = data['source'].replace(/&amp;/g,'&').replace(/&lt;/g,'<').replace(/&gt;/g,'>').replace(/&quot;/g,'"');

          ni = "<li class=\"hentry notice\" id=\"notice-"+data['id']+"\">"+
               "<div class=\"entry-title\">"+
               "<span class=\"vcard author\">"+
               "<a href=\""+user['profile_url']+"\" class=\"url\">"+
               "<img src=\""+user['profile_image_url']+"\" class=\"avatar photo\" width=\"48\" height=\"48\" alt=\""+user['screen_name']+"\"/>"+
               "<span class=\"nickname fn\">"+user['screen_name']+"</span>"+
               "</a>"+
               "</span>"+
               "<p class=\"entry-content\">"+html+"</p>"+
               "</div>"+
               "<div class=\"entry-content\">"+
               "<a class=\"timestamp\" rel=\"bookmark\" href=\""+data['url']+"\" >"+
               "<abbr class=\"published\" title=\""+data['created_at']+"\">a few seconds ago</abbr>"+
               "</a> "+
               "<span class=\"source\">"+
               "from "+
                "<span class=\"device\">"+source+"</span>"+ // may have a link
               "</span>";
          if (data['in_reply_to_status_id']) {
               ni = ni+" <a class=\"response\" href=\""+data['in_reply_to_status_url']+"\">in context</a>";
          }

          ni = ni+"</div>"+
            "<div class=\"notice-options\">";

          if (RealtimeUpdate._userid != 0) {
               var input = $("form#form_notice fieldset input#token");
               var session_key = input.val();
               ni = ni+RealtimeUpdate.makeFavoriteForm(data['id'], session_key);
               ni = ni+RealtimeUpdate.makeReplyLink(data['id'], data['user']['screen_name']);
               if (RealtimeUpdate._userid == data['user']['id']) {
                    ni = ni+RealtimeUpdate.makeDeleteLink(data['id']);
               }
          }

          ni = ni+"</div>"+
               "</li>";
          return ni;
     },

     makeFavoriteForm: function(id, session_key)
     {
          var ff;

          ff = "<form id=\"favor-"+id+"\" class=\"form_favor\" method=\"post\" action=\""+RealtimeUpdate._favorurl+"\">"+
                "<fieldset>"+
               "<legend>Favor this notice</legend>"+
               "<input name=\"token-"+id+"\" type=\"hidden\" id=\"token-"+id+"\" value=\""+session_key+"\"/>"+
               "<input name=\"notice\" type=\"hidden\" id=\"notice-n"+id+"\" value=\""+id+"\"/>"+
               "<input type=\"submit\" id=\"favor-submit-"+id+"\" name=\"favor-submit-"+id+"\" class=\"submit\" value=\"Favor\" title=\"Favor this notice\"/>"+
                "</fieldset>"+
               "</form>";
          return ff;
     },

     makeReplyLink: function(id, nickname)
     {
          var rl;
          rl = "<a class=\"notice_reply\" href=\""+RealtimeUpdate._replyurl+"?replyto="+nickname+"\" title=\"Reply to this notice\">Reply <span class=\"notice_id\">"+id+"</span></a>";
          return rl;
        },

     makeDeleteLink: function(id)
     {
          var dl, delurl;
          delurl = RealtimeUpdate._deleteurl.replace("0000000000", id);

          dl = "<a class=\"notice_delete\" href=\""+delurl+"\" title=\"Delete this notice\">Delete</a>";

          return dl;
     },

     addPopup: function(url, timeline, iconurl)
     {
         $('#content').prepend('<button id="realtime_timeline" title="Pop up in a window">Pop up</button>');

         $('#realtime_timeline').css({
             'margin':'0 0 18px 0',
             'background':'transparent url('+ iconurl + ') no-repeat 0% 30%',
             'padding':'0 0 0 20px',
             'display':'block',
             'float':'right',
             'border':'none',
             'cursor':'pointer',
             'color':$("a").css("color"),
             'font-weight':'bold',
             'font-size':'1em'
         });

         $('#realtime_timeline').click(function() {
             window.open(url,
                         timeline,
                         'toolbar=no,resizable=yes,scrollbars=yes,status=yes');

             return false;
         });
     },

     initPopupWindow: function()
     {
         window.resizeTo(500, 550);
         $('address').hide();
         $('#content').css({'width':'93.5%'});

         $('#form_notice').css({
            'margin':'18px 0 18px 1.795%',
            'width':'93%',
            'max-width':'451px'
         });

         $('#form_notice label[for=notice_data-text], h1').css({'display': 'none'});

         $('.notices li:first-child').css({'border-top-color':'transparent'});

         $('#form_notice label[for="notice_data-attach"], #form_notice #notice_data-attach').css({'top':'0'});

         $('#form_notice #notice_data-attach').css({
            'left':'auto',
            'right':'0'
         });
     }
}

