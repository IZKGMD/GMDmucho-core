<?php
declare(strict_types=1);

/** Shared identity and navigation for styled and CSS-free administration. */
function adminBrand(string $name): void
{
    echo '<div class="admin-brand"><div class="admin-brand-mark">';
    echo '<img class="admin-brand-icon" src="/assets/muchocore-icon.png?v=2" width="48" height="48" alt="MuchoCore" decoding="async">';
    echo '</div><div class="admin-brand-copy"><strong class="mucho-brand-name">'.h($name).'</strong><br><small>GDPS Administration</small></div></div>';
}

function adminPrimaryPages(): array
{
    return ['dashboard','players','levels','rating','clans','songs','news','clients','settings'];
}

function adminInterfaceUrl(bool $plain, array $query): string
{
    $safe=[];
    foreach (['page','q','filter','p','id','table','tab','edit'] as $key) {
        if (isset($query[$key]) && is_scalar($query[$key])) $safe[$key]=(string)$query[$key];
    }
    return ($plain?'/admin/plain/':'/admin/').($safe?'?'.http_build_query($safe):'');
}

function adminInterfaceSwitch(array $query=[]): void
{
    echo '<nav class="admin-interface-switch" aria-label="Admin interface"><span>Interface: </span>';
    foreach ([false=>'Styled admin',true=>'Plain admin (no CSS)'] as $plain=>$label) {
        echo '<a href="'.h(adminInterfaceUrl((bool)$plain,$query)).'"'.(!$plain?' data-styled-admin':'').
            (adminIsPlain()===(bool)$plain?' aria-current="true"':'').'>'.h($label).'</a>';
        if (!$plain) echo ' · ';
    }
    echo '</nav>';
}

function adminFooter(array $branding): void
{
    echo '<footer class="admin-footer"><p>'.h($branding['server_name']);
    if (!empty($branding['server_by_name']) && !empty($branding['social_url'])) {
        echo ' · Server by <a href="'.h($branding['social_url']).'" target="_blank" rel="noopener noreferrer">'.h($branding['server_by_name']).'</a>';
    }
    echo '</p><p>Powered by MuchoCore · © '.date('Y').' IZK · <a href="https://github.com/IZKGMD/GMDmucho-core" target="_blank" rel="noopener noreferrer">GitHub</a></p></footer>';
}
