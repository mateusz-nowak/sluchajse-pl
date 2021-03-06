$(function() {
    var $playlistContainer = $('.playlist-container');
    var $templatePlaylistTrack = $('#template-playlist');
    var PLAYLIST_STORAGE_KEY = 'playlisttracks';
    var $likebox = $('.likebox');

    var updatePlaylist = function() {
        var trackCollection = JSON.parse(localStorage.getItem(PLAYLIST_STORAGE_KEY));
        var $tbody = $playlistContainer.find('table tbody');

        $tbody.empty();

        if (trackCollection) {
            trackCollection.forEach(function(track) {
                $tbody.append(_.template($templatePlaylistTrack.html(), track));
            });
        }
    };

    var processLikebox = function() {
        var liked = sessionStorage.getItem('likebox-liked');

        if (liked == '1') {
            console.log('liked');
            $likebox.hide();
        } else {
            $(document).on('mousemove', function(e) {
                $likebox.css('left', e.pageX-20);
                $likebox.css('top', e.pageY-10);
                $likebox.fadeTo(0.1, 0.01);

                sessionStorage.setItem('likebox-liked', 1);
            });
        }
    };

    updatePlaylist();
    processLikebox();

    $playlistContainer.find('.remove').bind('click', function(e) {
        var item = localStorage.getItem(PLAYLIST_STORAGE_KEY);

        if (item) {
            var trackCollection = JSON.parse(localStorage.getItem(PLAYLIST_STORAGE_KEY));
        } else {
            return;
        }

        var trackId = $($(this).parents('tr')[0]).attr('data-id');

        var tracks = trackCollection.filter(function(row) {
            return trackId != row.id;
        });

        localStorage.setItem(PLAYLIST_STORAGE_KEY, JSON.stringify(tracks));

        updatePlaylist();

        e.preventDefault();
        e.stopPropagation();
    });

    $('.results-tracks .playlist').on('click', function(e) {
        var $tr = $($(this).parents('tr')[0]);

        if (localStorage.getItem(PLAYLIST_STORAGE_KEY)) {
            var trackCollection = JSON.parse(localStorage.getItem(PLAYLIST_STORAGE_KEY));
        } else {
            var trackCollection = [];
        }

        var track = {
            id: $tr.attr('data-id'),
            title: $tr.find('.title a').html(),
            url: $tr.find('.title a').attr('href')
        };

        var isAdded = trackCollection.filter(function(row) {
            return track.id == row.id;
        });

        if (isAdded.length == 0) {
            trackCollection.push(track);

            localStorage.setItem(PLAYLIST_STORAGE_KEY, JSON.stringify(trackCollection));
        }

        $(this).remove();

        updatePlaylist();

        e.preventDefault();
        e.stopPropagation();
    });

    audiojs.events.ready(function() {
        audiojs.createAll();
    });
});
