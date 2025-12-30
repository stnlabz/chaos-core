<?php
// Bootstrap-based share bar for Chaos CMS posts
declare(strict_types=1);

if (!function_exists('share_buttons')) {
    function share_buttons(string $absUrl, string $title): string {
        $u = rawurlencode($absUrl);
        $t = rawurlencode($title);

        $links = [
            ['label'=>'X','href'=>"https://twitter.com/intent/tweet?url={$u}&text={$t}",'icon'=>'bi-twitter-x'],
            ['label'=>'Facebook','href'=>"https://www.facebook.com/sharer/sharer.php?u={$u}",'icon'=>'bi-facebook'],
            ['label'=>'LinkedIn','href'=>"https://www.linkedin.com/sharing/share-offsite/?url={$u}",'icon'=>'bi-linkedin'],
            ['label'=>'Reddit','href'=>"https://www.reddit.com/submit?url={$u}&title={$t}",'icon'=>'bi-reddit'],
            ['label'=>'HN','href'=>"https://news.ycombinator.com/submitlink?u={$u}&t={$t}",'icon'=>'bi-newspaper'],
            ['label'=>'Email','href'=>"mailto:?subject={$t}&body={$u}",'icon'=>'bi-envelope'],
        ];

        $btns = '';
        foreach ($links as $a) {
            $btns .= '<a href="'.htmlspecialchars($a['href']).'" '
                .'class="btn btn-outline-secondary btn-sm me-1 mb-1" '
                .'target="_blank" rel="noopener noreferrer" '
                .'aria-label="Share on '.$a['label'].'">'
                .'<i class="bi '.$a['icon'].'"></i>'
                .'</a>';
        }

        // Copy link button
        $btns .= '<button type="button" class="btn btn-outline-secondary btn-sm mb-1 share-copy" '
                .'data-url="'.htmlspecialchars($absUrl).'" aria-label="Copy link">'
                .'<i class="bi bi-link-45deg"></i>'
                .'</button>';

        // JS for copy button
        $js = '<script>
document.addEventListener("click",function(e){
  var b=e.target.closest(".share-copy");
  if(!b) return;
  var url=b.getAttribute("data-url")||window.location.href;
  if(navigator.clipboard){
    navigator.clipboard.writeText(url).then(function(){
      var ico=b.querySelector("i");
      if(ico){ico.classList.replace("bi-link-45deg","bi-check2"); setTimeout(()=>ico.classList.replace("bi-check2","bi-link-45deg"),1500);}
    });
  } else {
    var ta=document.createElement("textarea");
    ta.value=url; document.body.appendChild(ta); ta.select();
    try{document.execCommand("copy");}catch(e){}
    document.body.removeChild(ta);
  }
});
        </script>';

        return '<div class="share-bar mt-3 mb-3">'.$btns.'</div>'.$js;
    }
}

