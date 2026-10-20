<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>O’CHANTIER · Recrutez des ouvriers du BTP qualifiés, ici et maintenant</title>
<meta name="description" content="O’CHANTIER, la plateforme de recrutement d’ouvriers du BTP à Abidjan : des ouvriers qualifiés et vérifiés, le suivi des présences et de l’avancement, le paiement sécurisé de vos chantiers.">
<meta name="theme-color" content="#14261F">
<meta property="og:type" content="website">
<meta property="og:locale" content="fr_CI">
<meta property="og:site_name" content="O’CHANTIER">
<meta property="og:title" content="O’CHANTIER · Trouvez vos prochains ouvriers, ici et maintenant">
<meta property="og:description" content="Des ouvriers qualifiés et disponibles, puis le suivi et le paiement de vos chantiers. Tout est dans la même application.">
<meta property="og:image" content="{{ asset('assets/app-accueil.webp') }}">
<link rel="icon" type="image/webp" href="assets/mono-couleur.webp">
<link rel="preconnect" href="https://unpkg.com" crossorigin>
<link rel="preload" as="image" href="assets/logo-couleur.webp">
<meta name="csrf-token" content="{{ csrf_token() }}">
<script src="{{ asset('support.js') }}"></script>
</head>
<body>
@verbatim
<x-dc>
<helmet>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Play:wght@400;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>html{scroll-behavior:smooth}body{margin:0;font-family:'Play',sans-serif;color:#14261F;background:#F8F7F3;-webkit-font-smoothing:antialiased}a{color:#1E6B4F;text-decoration:none}a:hover{color:#14261F}*{box-sizing:border-box}select,input,button,textarea{font-family:'Play',sans-serif}
.f-lbl{display:flex;flex-direction:column;gap:6px;font-size:13px;font-weight:700;min-width:0}
.f-row{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,220px),1fr));gap:14px}
.f-inp{width:100%;border:1.5px solid #D9D6CD;background:#FFFFFF;padding:13px 14px;font-size:15px;color:#14261F;outline:none;border-radius:0}
.f-inp:focus{border-color:#14261F}
textarea.f-inp{resize:vertical}
.f-chips{display:flex;gap:6px;flex-wrap:wrap}
.f-chip{border:1.5px solid #14261F;padding:8px 12px;font-size:13px;font-weight:700;cursor:pointer}
.f-file{border:2px dashed #9FA8A3;background:#FFFFFF;padding:14px;font-size:13px;font-weight:600;color:#5B645F;cursor:pointer}
.f-file:focus-within{border-color:#14261F;outline:2px solid #FAB82A}
.f-err{background:#FDE3E1;color:#9B2C1F;padding:10px 14px;font-size:13px;font-weight:700}
.sr-only{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}
.kicker{font-family:'JetBrains Mono',monospace;font-size:12px;letter-spacing:.14em;text-transform:uppercase}
.h-sec{margin:14px 0 0;font-size:clamp(25px,3vw,40px);line-height:.95;font-weight:900;text-transform:uppercase;text-wrap:balance}
.card{background:#FFFFFF;border-radius:18px;box-shadow:0 1px 3px rgba(14,74,58,.08)}
.tick{display:flex;gap:10px}.tick::before{content:'✓';color:#3EB884;font-weight:900}
.worker{position:absolute;bottom:16%;width:22px;height:58px}
.worker>i{position:absolute;display:block}
.worker .hat{left:5px;top:0;width:13px;height:7px;border-radius:7px 7px 0 0;background:#FAB82A}
.worker .head{left:6px;top:6px;width:11px;height:10px;border-radius:50%;background:#CFEDE0}
.worker .body{left:3px;top:17px;width:17px;height:22px;border-radius:4px}
.worker .body::after{content:'';position:absolute;left:0;right:0;top:12px;height:3px;background:#E8E6DF}
.worker .leg{left:6px;top:38px;width:5px;height:20px;background:#9FA8A3;border-radius:2px;transform-origin:top center}
.worker .leg+.leg{left:12px}
.floor{flex:1;border-top:6px solid #9FA8A3;background:repeating-linear-gradient(90deg,#5B645F 0 8px,transparent 8px 25%);transform-origin:bottom center;position:relative}
.floor>i{position:absolute;left:14%;right:14%;top:30%;bottom:22%;background:repeating-linear-gradient(90deg,rgba(62,184,132,.18) 0 18%,transparent 18% 25%)}
:focus-visible{outline:3px solid #FAB82A;outline-offset:2px}
@media (prefers-reduced-motion:reduce){html{scroll-behavior:auto}*,*::before,*::after{transition-duration:.01ms!important}}
</style>
</helmet>

<sc-if value="{{ showCover }}" hint-placeholder-val="{{ true }}">
<div data-screen-label="00 Page de garde" style="position:fixed;inset:0;z-index:100;background:#14261F;overflow:hidden;transform:{{ coverTransform }};transition:transform 1s cubic-bezier(.75,0,.2,1)">
  <div style="position:absolute;inset:0;background:#14261F"></div>
  <div ref="{{ sceneRef }}" aria-hidden="true" style="position:absolute;right:0;bottom:0;width:clamp(560px,64%,1040px);height:86%;opacity:{{ sceneOpacity }};transition:opacity .4s">
    <div data-a="blink" style="position:absolute;left:31.2%;top:1%;width:10px;height:10px;border-radius:50%;background:#FAB82A"></div>
    <div style="position:absolute;left:30%;width:3%;bottom:16%;top:4%;border-left:3px solid #FAB82A;border-right:3px solid #FAB82A;background:repeating-linear-gradient(45deg,#FAB82A 0 2px,transparent 2px 14px),repeating-linear-gradient(-45deg,#FAB82A 0 2px,transparent 2px 14px)"></div>
    <div style="position:absolute;left:16%;right:4%;top:8%;height:2.4%;border-top:3px solid #FAB82A;border-bottom:3px solid #FAB82A;background:repeating-linear-gradient(60deg,#FAB82A 0 2px,transparent 2px 14px)"></div>
    <div style="position:absolute;left:16%;width:7%;top:10.6%;height:6%;background:#5B645F"></div>
    <div style="position:absolute;left:33.2%;width:3.4%;top:10.6%;height:5%;background:#E8E6DF;border-radius:0 0 3px 3px"><div style="position:absolute;left:20%;right:20%;top:20%;height:40%;background:#14261F"></div></div>
    <div style="position:absolute;left:37%;right:6%;top:10.6%;bottom:16%">
      <div data-a="trolley" style="position:absolute;top:0;bottom:0;left:10%;width:24px;display:flex;flex-direction:column;align-items:center">
        <div style="width:24px;height:10px;background:#E8E6DF;flex:none"></div>
        <div data-a="cable" style="width:2px;height:20%;background:#CFEDE0;flex:none"></div>
        <div style="width:8px;height:8px;border:2px solid #CFEDE0;border-top:none;border-radius:0 0 6px 6px;flex:none"></div>
        <div style="width:70px;height:16px;background:repeating-linear-gradient(90deg,#9FA8A3 0 18px,#8A918C 18px 20px);flex:none;margin-top:-1px"></div>
      </div>
    </div>
    <div style="position:absolute;left:50%;width:38%;bottom:16%;height:58%;display:flex;flex-direction:column-reverse"><div data-a="floor" data-i="0" class="floor"><i></i></div><div data-a="floor" data-i="1" class="floor"><i></i></div><div data-a="floor" data-i="2" class="floor"><i></i></div><div data-a="floor" data-i="3" class="floor"><i></i></div><div data-a="floor" data-i="4" class="floor"><i></i></div><div data-a="floor" data-i="5" class="floor"></div></div>
    <div style="position:absolute;left:46.5%;width:3.5%;bottom:16%;height:52%;border-left:2px solid #FAB82A;border-right:2px solid #FAB82A;background:repeating-linear-gradient(0deg,#FAB82A 0 2px,transparent 2px 30px);opacity:.85"></div>
    <div style="position:absolute;left:0;right:0;bottom:0;height:16%;background:#1B342A"><div style="height:6px;background:repeating-linear-gradient(-45deg,#FAB82A 0 10px,#14261F 10px 20px)"></div></div>
    <div style="position:absolute;left:8%;bottom:16%;width:120px;height:44px;border-radius:120px 120px 0 0;background:#5B645F"></div>
    <div style="position:absolute;left:88%;bottom:16%;width:56px;display:flex;flex-direction:column;gap:2px"><div style="height:10px;background:#8A918C"></div><div style="height:10px;background:#8A918C;margin:0 6px"></div><div style="height:10px;background:#8A918C"></div></div>
    <div data-a="worker" data-dur="7000" data-dist="180" class="worker" style="left:12%"><i class="hat"></i><i class="head"></i><i class="body" style="background:#3EB884"></i><i class="leg" data-a="leg"></i><i class="leg" data-a="leg2"></i></div>
    <div data-a="worker" data-dur="9500" data-dist="240" class="worker" style="left:24%"><i class="hat"></i><i class="head"></i><i class="body" style="background:#FAB82A"></i><i style="left:-14px;top:14px;width:50px;height:4px;background:#E8E6DF;border-radius:2px"></i><i class="leg" data-a="leg"></i><i class="leg" data-a="leg2"></i></div>
    <div data-a="worker" data-dur="6200" data-dist="140" class="worker" style="left:40%"><i class="hat"></i><i class="head"></i><i class="body" style="background:#3EB884"></i><i class="leg" data-a="leg"></i><i class="leg" data-a="leg2"></i></div>
    <div data-a="worker" data-dur="8200" data-dist="160" class="worker" style="left:62%"><i class="hat"></i><i class="head"></i><i class="body" style="background:#1E6B4F"></i><i class="leg" data-a="leg"></i><i class="leg" data-a="leg2"></i></div>
    <div data-a="worker" data-dur="10500" data-dist="120" class="worker" style="left:78%"><i class="hat"></i><i class="head"></i><i class="body" style="background:#FAB82A"></i><i class="leg" data-a="leg"></i><i class="leg" data-a="leg2"></i></div>
  </div>
  <video ref="{{ videoRef }}" loop="" playsinline="" preload="none" style="position:absolute;inset:0;width:100%;height:100%;object-fit:cover;opacity:0;transition:opacity 1s"></video>
  <div style="position:absolute;inset:0;background:linear-gradient(90deg,rgba(20,38,31,.95) 0%,rgba(20,38,31,.85) 40%,rgba(20,38,31,.35) 62%,rgba(20,38,31,0) 76%),linear-gradient(180deg,rgba(20,38,31,0) 60%,rgba(20,38,31,.75) 100%)"></div>
  <div ref="{{ coverStripeRef }}" style="position:absolute;left:0;right:0;top:0;height:12px;background:repeating-linear-gradient(-45deg,#FAB82A 0 14px,#14261F 14px 28px);background-size:39.6px 39.6px"></div>
  <div style="position:relative;height:100%;display:flex;flex-direction:column;justify-content:space-between;padding:clamp(36px,5vw,64px) clamp(24px,5vw,64px)">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap">
      <img src="assets/logo-couleur.webp" alt="O'CHANTIER" style="width:clamp(130px,14vw,180px);display:block">
      <div style="display:flex;align-items:center;gap:10px;font-family:'JetBrains Mono',monospace;font-size:11px;letter-spacing:.14em;text-transform:uppercase;color:#CFEDE0"><span ref="{{ liveDotRef }}" style="width:8px;height:8px;border-radius:50%;background:#3EB884"></span>Ouvriers disponibles maintenant</div>
    </div>
    <div ref="{{ coverContentRef }}" style="display:flex;flex-direction:column;gap:clamp(20px,3vw,32px);max-width:1100px">
      <div class="kicker" style="color:#FAB82A">La plateforme de recrutement d’ouvriers du BTP</div>
      <div style="margin:0;font-size:clamp(32px,5.3vw,76px);line-height:.9;font-weight:900;text-transform:uppercase;letter-spacing:-.02em;color:#F8F7F3;text-wrap:balance;max-width:820px">Trouvez vos<br>prochains ouvriers. <span style="color:#3EB884">Ici et<br>maintenant.</span></div>
      <div style="display:flex;align-items:center;gap:24px;flex-wrap:wrap">
        <button onClick="{{ enter }}" style="display:flex;align-items:center;gap:16px;background:#FAB82A;color:#14261F;border:none;padding:10px 10px 10px 28px;font-size:clamp(15px,1.4vw,18px);font-weight:900;text-transform:uppercase;letter-spacing:.02em;cursor:pointer;transition:background .2s,transform .2s" style-hover="background:#FFFFFF;transform:translateY(-2px)">Découvrir O’CHANTIER<span style="width:48px;height:48px;border-radius:50%;background:#14261F;color:#FAB82A;display:flex;align-items:center;justify-content:center;font-size:22px">↓</span></button>
        <span style="font-size:14px;color:#CFEDE0;max-width:340px;line-height:1.5">Des ouvriers qualifiés et disponibles, puis le suivi et le paiement de vos chantiers. Tout est dans la même application.</span>
      </div>
    </div>
  </div>
</div>
</sc-if>

<sc-if value="{{ formOpen }}" hint-placeholder-val="{{ false }}">
<div data-screen-label="Formulaire ouvrier" style="position:fixed;inset:0;z-index:90;background:rgba(20,38,31,.65);overflow-y:auto;padding:clamp(12px,4vw,48px) 12px;display:flex;justify-content:center;align-items:flex-start">
  <div ref="{{ formRef }}" style="width:min(100%,720px);background:#F8F7F3;display:flex;flex-direction:column;box-shadow:0 40px 80px -30px rgba(0,0,0,.5)">
    <div style="height:10px;background:repeating-linear-gradient(-45deg,#FAB82A 0 10px,#14261F 10px 20px)"></div>
    <div style="padding:22px clamp(18px,4vw,32px) 0;display:flex;justify-content:space-between;align-items:flex-start;gap:16px">
      <div><div style="font-family:'JetBrains Mono',monospace;font-size:11px;letter-spacing:.14em;text-transform:uppercase;color:#1E6B4F">Inscription ouvrier · gratuite</div><div style="font-size:clamp(24px,3vw,32px);font-weight:900;text-transform:uppercase;line-height:1;margin-top:8px">Devenir ouvrier O’CHANTIER</div></div>
      <button onClick="{{ closeForm }}" aria-label="Fermer" style="width:44px;height:44px;flex:none;border:none;background:#14261F;color:#F8F7F3;font-size:18px;cursor:pointer">✕</button>
    </div>
    <sc-if value="{{ hasTarget }}" hint-placeholder-val="{{ false }}"><div style="margin:16px clamp(18px,4vw,32px) 0;background:#FEEDCA;padding:12px 14px;font-size:14px"><strong>Candidature :</strong> {{ targetLabel }}</div></sc-if>
    <sc-if value="{{ formNotDone }}" hint-placeholder-val="{{ true }}">
      <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:6px;padding:18px clamp(18px,4vw,32px) 0">
        <sc-for list="{{ fSteps }}" as="s" hint-placeholder-count="3"><div style="display:flex;flex-direction:column;gap:6px"><div style="height:5px;background:{{ s.bar }}"></div><span style="font-size:12px;font-weight:700;color:{{ s.fg }}">{{ s.label }}</span></div></sc-for>
      </div>
      <div style="padding:20px clamp(18px,4vw,32px);display:flex;flex-direction:column;gap:16px">
        <sc-if value="{{ f0 }}" hint-placeholder-val="{{ true }}">
          <div class="f-row">
            <label class="f-lbl">Prénom *<input value="{{ fv.prenom }}" onChange="{{ on.prenom }}" placeholder="Adama" class="f-inp"></label>
            <label class="f-lbl">Nom *<input value="{{ fv.nom }}" onChange="{{ on.nom }}" placeholder="Ouattara" class="f-inp"></label>
            <label class="f-lbl">Téléphone *<input type="tel" value="{{ fv.tel }}" onChange="{{ on.tel }}" placeholder="+225 07 00 00 00 00" class="f-inp"></label>
            <label class="f-lbl">E-mail<input type="email" value="{{ fv.email }}" onChange="{{ on.email }}" placeholder="facultatif" class="f-inp"></label>
            <label class="f-lbl">Date de naissance<input type="date" value="{{ fv.naissance }}" onChange="{{ on.naissance }}" class="f-inp"></label>
            <label class="f-lbl">Genre<select value="{{ fv.genre }}" onChange="{{ on.genre }}" class="f-inp"><option value="">—</option><option>Homme</option><option>Femme</option></select></label>
          </div>
          <div class="f-row">
            <label class="f-lbl">Photo de profil<span class="f-file">{{ photoLabel }}<input type="file" accept="image/*" onChange="{{ on.photo }}" class="sr-only"></span></label>
            <label class="f-lbl">Pièce d’identité (CNI, passeport) *<span class="f-file">{{ cniLabel }}<input type="file" accept="image/*,.pdf" onChange="{{ on.cni }}" class="sr-only"></span></label>
          </div>
        </sc-if>
        <sc-if value="{{ f1 }}" hint-placeholder-val="{{ false }}">
          <div class="f-row">
            <label class="f-lbl">Spécialité principale *<select value="{{ fv.metier }}" onChange="{{ on.metier }}" class="f-inp"><option value="">Choisir…</option><option value="Maçon">Maçon</option><option value="Électricien">Électricien</option><option value="Plombier">Plombier</option><option value="Peintre">Peintre</option><option value="Carreleur">Carreleur</option><option value="Ferrailleur">Ferrailleur</option><option value="Coffreur">Coffreur</option><option value="Plaquiste">Plaquiste</option><option value="Menuisier">Menuisier</option><option value="Soudeur">Soudeur</option><option value="Chef de chantier">Chef de chantier</option></select></label>
            <label class="f-lbl">Tarif journalier souhaité<input type="number" min="0" step="1000" value="{{ fv.tarif }}" onChange="{{ on.tarif }}" placeholder="25 000 FCFA" class="f-inp"></label>
          </div>
          <label class="f-lbl">Années d’expérience · <span style="color:#1E6B4F">{{ expLabel }}</span><input type="range" min="0" max="30" value="{{ fv.exp }}" onChange="{{ on.exp }}" style="width:100%;accent-color:#1E6B4F"></label>
          <div class="f-lbl">Niveau<div class="f-chips"><sc-for list="{{ levelList }}" as="c" hint-placeholder-count="4"><button type="button" class="f-chip" aria-pressed="{{ c.on }}" onClick="{{ c.toggle }}" style="background:{{ c.bg }};color:{{ c.fg }}">{{ c.label }}</button></sc-for></div></div>
          <div class="f-lbl">Autres compétences<div class="f-chips"><sc-for list="{{ secList }}" as="c" hint-placeholder-count="4"><button type="button" class="f-chip" aria-pressed="{{ c.on }}" onClick="{{ c.toggle }}" style="background:{{ c.bg }};color:{{ c.fg }}">{{ c.label }}</button></sc-for></div></div>
          <div class="f-lbl">Diplômes &amp; habilitations<div class="f-chips"><sc-for list="{{ certList }}" as="c" hint-placeholder-count="4"><button type="button" class="f-chip" aria-pressed="{{ c.on }}" onClick="{{ c.toggle }}" style="background:{{ c.bg }};color:{{ c.fg }}">{{ c.label }}</button></sc-for></div></div>
          <label class="f-lbl">Présentez votre expérience<textarea value="{{ fv.bio }}" onChange="{{ on.bio }}" rows="3" placeholder="Chantiers réalisés, spécialités, références…" class="f-inp"></textarea></label>
        </sc-if>
        <sc-if value="{{ f2 }}" hint-placeholder-val="{{ false }}">
          <div class="f-row">
            <label class="f-lbl">Commune / ville *<select value="{{ fv.commune }}" onChange="{{ on.commune }}" class="f-inp"><option value="">Choisir…</option><option value="Abobo">Abobo</option><option value="Adjamé">Adjamé</option><option value="Anyama">Anyama</option><option value="Attécoubé">Attécoubé</option><option value="Bingerville">Bingerville</option><option value="Cocody">Cocody</option><option value="Grand-Bassam">Grand-Bassam</option><option value="Koumassi">Koumassi</option><option value="Marcory">Marcory</option><option value="Plateau">Plateau</option><option value="Port-Bouët">Port-Bouët</option><option value="Songon">Songon</option><option value="Treichville">Treichville</option><option value="Yopougon">Yopougon</option><option value="Autre ville">Autre ville</option></select></label>
            <label class="f-lbl">Quartier<input value="{{ fv.quartier }}" onChange="{{ on.quartier }}" placeholder="Riviera 3, Niangon…" class="f-inp"></label>
          </div>
          <div class="f-lbl">Distance maximale de déplacement<div class="f-chips"><sc-for list="{{ radiusList }}" as="c" hint-placeholder-count="4"><button type="button" class="f-chip" aria-pressed="{{ c.on }}" onClick="{{ c.toggle }}" style="background:{{ c.bg }};color:{{ c.fg }}">{{ c.label }}</button></sc-for></div></div>
          <div class="f-lbl">Disponibilité<div class="f-chips"><sc-for list="{{ dispoList }}" as="c" hint-placeholder-count="4"><button type="button" class="f-chip" aria-pressed="{{ c.on }}" onClick="{{ c.toggle }}" style="background:{{ c.bg }};color:{{ c.fg }}">{{ c.label }}</button></sc-for></div></div>
          <div class="f-lbl">Jours disponibles<div class="f-chips"><sc-for list="{{ daysList }}" as="c" hint-placeholder-count="4"><button type="button" class="f-chip" aria-pressed="{{ c.on }}" onClick="{{ c.toggle }}" style="background:{{ c.bg }};color:{{ c.fg }}">{{ c.label }}</button></sc-for></div></div>
          <div class="f-row">
            <div class="f-lbl">Être payé par<div class="f-chips"><sc-for list="{{ payList }}" as="c" hint-placeholder-count="4"><button type="button" class="f-chip" aria-pressed="{{ c.on }}" onClick="{{ c.toggle }}" style="background:{{ c.bg }};color:{{ c.fg }}">{{ c.label }}</button></sc-for></div></div>
            <label class="f-lbl">Numéro de paiement<input type="tel" value="{{ fv.paynum }}" onChange="{{ on.paynum }}" placeholder="+225 07 00 00 00 00" class="f-inp"></label>
          </div>
          <label style="display:flex;gap:10px;align-items:flex-start;font-size:13px;line-height:1.5;cursor:pointer"><input type="checkbox" checked="{{ fv.consent }}" onChange="{{ on.consent }}" style="width:20px;height:20px;accent-color:#1E6B4F;flex:none;margin-top:1px">J’accepte les conditions d’utilisation et la vérification de mon identité et de mes qualifications par O’CHANTIER. *</label>
        </sc-if>
        <sc-if value="{{ hasErr }}" hint-placeholder-val="{{ false }}"><div class="f-err" role="alert">{{ ferr }}</div></sc-if>
      </div>
      <div style="padding:16px clamp(18px,4vw,32px) 24px;border-top:1px solid #E8E6DF;display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap">
        <button onClick="{{ fPrev }}" style="background:transparent;border:2px solid #14261F;padding:14px 22px;font-size:14px;font-weight:800;cursor:pointer;visibility:{{ prevVis }}">← Retour</button>
        <button onClick="{{ fNext }}" style="background:#14261F;color:#F8F7F3;border:none;padding:14px 26px;font-size:14px;font-weight:900;text-transform:uppercase;cursor:pointer" style-hover="background:#1E6B4F">{{ nextLabel }}</button>
      </div>
    </sc-if>
    <sc-if value="{{ formDone }}" hint-placeholder-val="{{ false }}">
      <div style="padding:28px clamp(18px,4vw,32px) 32px;display:flex;flex-direction:column;gap:16px">
        <span style="width:68px;height:68px;border-radius:50%;background:#3EB884;display:flex;align-items:center;justify-content:center;font-size:32px;font-weight:900">✓</span>
        <div style="font-size:28px;font-weight:900;text-transform:uppercase;line-height:1">Profil envoyé, {{ fv.prenom }} !</div>
        <div style="background:#FFFFFF;padding:14px 16px;font-size:14px;line-height:1.7">{{ summary }}</div>
        <p style="margin:0;font-size:15px;line-height:1.6;color:#3B4540">Votre candidature est transmise au Back Office O’CHANTIER. Notre équipe vérifie votre identité et vos qualifications sous 48 h, puis vous contacte par téléphone ou SMS pour vous proposer des missions près de chez vous.</p>
        <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
                    <button onClick="{{ closeForm }}" style="background:transparent;border:2px solid #14261F;padding:12px 20px;font-size:14px;font-weight:800;cursor:pointer">Fermer</button>
        </div>
      </div>
    </sc-if>
  </div>
</div>
</sc-if>

<sc-if value="{{ showTop }}" hint-placeholder-val="{{ false }}">
<button onClick="{{ toTop }}" aria-label="Remonter en haut de la page" title="Remonter" style="position:fixed;right:clamp(16px,3vw,32px);bottom:clamp(16px,3vw,32px);z-index:80;width:56px;height:56px;border-radius:50%;border:3px solid #F8F7F3;background:#FAB82A;color:#14261F;font-size:24px;font-weight:900;cursor:pointer;box-shadow:0 10px 24px -8px rgba(20,38,31,.55);display:flex;align-items:center;justify-content:center;transition:transform .2s,background .2s" style-hover="background:#3EB884;transform:translateY(-3px)">↑</button>
</sc-if>

<header style="position:sticky;top:0;z-index:40;background:rgba(248,247,243,.95);backdrop-filter:blur(10px);border-bottom:1px solid #E8E6DF">
  <div style="max-width:1240px;margin:0 auto;padding:14px clamp(20px,4vw,48px);display:flex;align-items:center;justify-content:space-between;gap:24px">
    <a href="#accueil" onClick="{{ logoClick }}" title="Revoir la page de garde" style="display:block"><img src="assets/logo-couleur.webp" alt="O'CHANTIER" style="width:150px;display:block"></a>
    <sc-if value="{{ isDesktop }}" hint-placeholder-val="{{ true }}">
      <nav style="display:flex;align-items:center;gap:32px;font-size:15px;font-weight:600">
                <a href="#solution" style="color:#14261F" style-hover="color:#1E6B4F">La solution</a>
        <a href="#tarifs" style="color:#14261F" style-hover="color:#1E6B4F">Tarifs</a>
                <a href="#carte" style="color:#14261F" style-hover="color:#1E6B4F">Chantiers près de vous</a>
        <a href="#contact" style="color:#14261F" style-hover="color:#1E6B4F">Contact</a>
        <button onClick="{{ openForm }}" style="background:none;border:2px solid #14261F;color:#14261F;padding:10px 16px;font-size:15px;font-weight:800;cursor:pointer" style-hover="background:#FAB82A;border-color:#FAB82A">Devenir ouvrier</button>
        <a href="#telecharger" style="background:#14261F;color:#F8F7F3;padding:12px 20px;font-weight:800" style-hover="background:#1E6B4F;color:#FFFFFF">Télécharger l’app</a>
      </nav>
    </sc-if>
    <sc-if value="{{ isMobile }}" hint-placeholder-val="{{ false }}">
      <button onClick="{{ toggleMenu }}" aria-label="Menu" style="width:48px;height:48px;background:#14261F;color:#F8F7F3;border:none;font-size:20px;cursor:pointer">{{ menuIcon }}</button>
    </sc-if>
  </div>
  <sc-if value="{{ menuOpen }}" hint-placeholder-val="{{ false }}">
    <nav style="display:flex;flex-direction:column;padding:8px clamp(20px,4vw,48px) 24px;font-size:20px;font-weight:800;border-top:1px solid #E8E6DF">
            <a href="#solution" onClick="{{ closeMenu }}" style="color:#14261F;padding:14px 0;border-bottom:1px solid #E8E6DF">La solution</a>
      <a href="#tarifs" onClick="{{ closeMenu }}" style="color:#14261F;padding:14px 0;border-bottom:1px solid #E8E6DF">Tarifs</a>
            <a href="#carte" onClick="{{ closeMenu }}" style="color:#14261F;padding:14px 0;border-bottom:1px solid #E8E6DF">Chantiers près de vous</a>
      <a href="#contact" onClick="{{ closeMenu }}" style="color:#14261F;padding:14px 0;border-bottom:1px solid #E8E6DF">Nous contacter</a>
      <button onClick="{{ openForm }}" style="text-align:left;background:none;border:none;border-bottom:1px solid #E8E6DF;color:#14261F;padding:14px 0;font-size:20px;font-weight:800;cursor:pointer">Devenir ouvrier</button>
      <a href="#telecharger" onClick="{{ closeMenu }}" style="background:#FAB82A;color:#14261F;padding:16px;margin-top:14px;text-align:center">Télécharger l’app</a>
    </nav>
  </sc-if>
</header>

<main id="accueil">
  <section data-screen-label="01 Accueil · Démo" style="max-width:1240px;margin:0 auto;padding:clamp(56px,7vw,96px) clamp(20px,4vw,48px) clamp(56px,7vw,96px);display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,460px),1fr));gap:clamp(40px,5vw,72px);align-items:center">
    <div data-reveal="" style="display:flex;flex-direction:column;gap:26px">
      <div style="display:flex;align-items:center;gap:12px;font-family:'JetBrains Mono',monospace;font-size:12px;letter-spacing:.14em;text-transform:uppercase;color:#1E6B4F"><span style="width:28px;height:6px;background:#FAB82A"></span>Promoteurs · Entreprises générales · Bureaux techniques</div>
      <h1 style="margin:0;font-size:clamp(29px,4vw,56px);line-height:.92;font-weight:900;text-transform:uppercase;letter-spacing:-.02em;text-wrap:balance">Des ouvriers qualifiés <span style="background:#FAB82A;padding:0 .1em">disponibles</span> pour votre chantier.</h1>
      <p style="margin:0;font-size:clamp(16px,1.4vw,19px);line-height:1.6;max-width:560px;color:#3B4540;text-wrap:pretty">Testez l’application client. Choisissez un chantier, indiquez le nombre d’ouvriers par métier et lancez la recherche. Vous recevez une offre d’ouvriers vérifiés, que vous confirmez en payant l’acompte.</p>
      <div style="display:flex;flex-direction:column;border-top:2px solid #14261F">
        <sc-for list="{{ demoSteps }}" as="s" hint-placeholder-count="4">
          <button onClick="{{ s.go }}" style="display:grid;grid-template-columns:40px minmax(0,1fr);gap:14px;align-items:center;text-align:left;padding:13px 12px;border:none;border-bottom:1px solid #D9D6CD;background:{{ s.bg }};color:{{ s.fg }};cursor:pointer;transition:background .25s,color .25s">
            <span style="width:36px;height:36px;border-radius:50%;background:{{ s.dot }};color:#14261F;display:flex;align-items:center;justify-content:center;font-weight:900;font-size:15px">{{ s.n }}</span>
            <span style="display:flex;flex-direction:column;gap:2px"><span style="font-size:17px;font-weight:800">{{ s.title }}</span><span style="font-size:14px;opacity:.8">{{ s.text }}</span></span>
          </button>
        </sc-for>
      </div>
      <div style="display:flex;gap:10px;flex-wrap:wrap">
  <a href="#telecharger" style="display:flex;flex-direction:column;justify-content:center;background:#14261F;color:#FFFFFF;border-radius:10px;padding:8px 18px;min-width:150px;line-height:1.1" style-hover="background:#1E6B4F;color:#FFFFFF"><span style="font-size:11px">Télécharger dans l’</span><span style="font-size:19px;font-weight:700">App Store</span></a>
  <a href="#telecharger" style="display:flex;flex-direction:column;justify-content:center;background:#14261F;color:#FFFFFF;border-radius:10px;padding:8px 18px;min-width:150px;line-height:1.1" style-hover="background:#1E6B4F;color:#FFFFFF"><span style="font-size:11px">DISPONIBLE SUR</span><span style="font-size:19px;font-weight:700">Google Play</span></a>
</div>
    </div>

    <div data-reveal="" style="position:relative;display:flex;justify-content:center;padding:20px 0">
      <div style="position:absolute;right:2%;top:-10px;width:220px;height:220px;border-radius:50%;border:38px solid #FAB82A"></div>
      <div style="position:absolute;left:0;bottom:30px;font-family:'JetBrains Mono',monospace;font-size:11px;letter-spacing:.12em;text-transform:uppercase;color:#1E6B4F;writing-mode:vertical-rl;transform:rotate(180deg)">Démo interactive · touchez l’écran</div>
      <div style="position:relative;width:min(100%,350px);background:#1C1F1E;border-radius:52px;padding:11px;box-shadow:0 40px 80px -40px rgba(20,38,31,.8),inset 0 0 0 2px #3A3F3D">
        <div style="background:#F5F7F5;border-radius:42px;overflow:hidden;height:700px;display:flex;flex-direction:column;position:relative;color:#0E4A3A">
          <div style="display:flex;justify-content:space-between;align-items:center;padding:14px 26px 4px;font-size:14px;font-weight:700;color:#14261F;flex:none"><span>9:41</span><span style="width:96px;height:28px;border-radius:16px;background:#000000"></span><span style="font-size:11px">▮▮▮ ▭</span></div>
          <div ref="{{ screenRef }}" style="flex:1;min-height:0;display:flex;flex-direction:column;position:relative">

          <sc-if value="{{ s0 }}" hint-placeholder-val="{{ true }}">
            <div style="position:absolute;right:-90px;top:110px;width:260px;height:260px;border-radius:50%;background:#E4F3EC"></div>
            <div style="flex:1;overflow-y:auto;padding:10px 0 80px;display:flex;flex-direction:column;gap:14px;position:relative">
              <div style="display:flex;justify-content:space-between;align-items:center;padding:0 20px"><img src="assets/logo-couleur.webp" alt="O'CHANTIER" style="width:140px;display:block"><span style="position:relative;width:40px;height:40px;border-radius:50%;background:#E4F3EC;display:flex;align-items:center;justify-content:center;font-size:16px">◔<span style="position:absolute;right:-2px;top:-2px;width:18px;height:18px;border-radius:50%;background:#F2B21E;color:#14261F;font-size:10px;font-weight:800;display:flex;align-items:center;justify-content:center">6</span></span></div>
              <div style="padding:0 20px"><div style="font-size:11px;letter-spacing:.14em;color:#6B7570;font-weight:600">BONJOUR</div><div style="font-size:28px;font-weight:900;line-height:1.05">OUEGO</div><div style="font-size:13px;color:#6B7570;margin-top:4px">Trouvez des ouvriers pour vos chantiers ici</div></div>
              <div style="display:flex;justify-content:space-between;padding:0 20px;font-size:15px;font-weight:800"><span>Mes chantiers</span><span style="font-weight:500;font-size:13px">Voir tout ›</span></div>
              <div style="display:flex;gap:12px;overflow-x:auto;padding:0 20px 4px">
                <div class="card" style="flex:none;width:220px;padding:6px 6px 12px;display:flex;flex-direction:column;gap:6px"><img src="assets/photo-villa.webp" alt="" style="width:100%;height:96px;object-fit:cover;border-radius:12px;display:block"><div style="padding:0 6px;display:flex;flex-direction:column;gap:5px"><span style="font-size:14px;font-weight:800">Villa Riviera</span><span style="font-size:11px;color:#6B7570">◎ Riviera 3, Abidjan</span><span style="display:flex;justify-content:space-between;font-size:11px;color:#6B7570">Progression<strong style="color:#0E4A3A">30 %</strong></span><span style="height:5px;border-radius:3px;background:#E8ECE9"><span style="display:block;width:30%;height:100%;border-radius:3px;background:#2FB57E"></span></span><button onClick="{{ startResa }}" style="margin-top:4px;background:#0E4A3A;color:#FFFFFF;border:none;border-radius:10px;padding:10px;font-size:12px;font-weight:700;cursor:pointer">Voir le chantier →</button></div></div>
                <div class="card" style="flex:none;width:220px;padding:6px 6px 12px;display:flex;flex-direction:column;gap:6px"><img src="assets/photo-horizon.webp" alt="" style="width:100%;height:96px;object-fit:cover;border-radius:12px;display:block"><div style="padding:0 6px;display:flex;flex-direction:column;gap:5px"><span style="font-size:14px;font-weight:800">Immeuble Horizon</span><span style="font-size:11px;color:#6B7570">◎ Marcory, Abidjan</span><span style="display:flex;justify-content:space-between;font-size:11px;color:#6B7570">Progression<strong style="color:#0E4A3A">65 %</strong></span><span style="height:5px;border-radius:3px;background:#E8ECE9"><span style="display:block;width:65%;height:100%;border-radius:3px;background:#2FB57E"></span></span><button onClick="{{ startResa }}" style="margin-top:4px;background:#0E4A3A;color:#FFFFFF;border:none;border-radius:10px;padding:10px;font-size:12px;font-weight:700;cursor:pointer">Voir le chantier →</button></div></div>
              </div>
              <div style="display:flex;justify-content:space-between;padding:0 20px;font-size:15px;font-weight:800"><span>Métiers les plus demandés</span><span style="font-weight:500;font-size:13px">Voir tout ›</span></div>
              <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:8px;padding:0 20px">
                <button onClick="{{ startResa }}" class="card" style="border:none;padding:14px 4px;display:flex;flex-direction:column;align-items:center;gap:8px;cursor:pointer;font-size:12px;font-weight:700;color:#0E4A3A"><span style="width:36px;height:36px;border-radius:50%;background:#E4F3EC;display:flex;align-items:center;justify-content:center;font-weight:900">M</span>Maçon</button>
                <button onClick="{{ startResa }}" style="background:#FFF4DA;border:1px solid #F5D98A;border-radius:18px;padding:14px 4px;display:flex;flex-direction:column;align-items:center;gap:8px;cursor:pointer;font-size:12px;font-weight:700;color:#0E4A3A"><span style="width:36px;height:36px;border-radius:50%;background:#FDE8B0;color:#F2B21E;display:flex;align-items:center;justify-content:center;font-weight:900">É</span>Électricien</button>
                <button onClick="{{ startResa }}" class="card" style="border:none;padding:14px 4px;display:flex;flex-direction:column;align-items:center;gap:8px;cursor:pointer;font-size:12px;font-weight:700;color:#0E4A3A"><span style="width:36px;height:36px;border-radius:50%;background:#E4F3EC;display:flex;align-items:center;justify-content:center;font-weight:900">P</span>Plombier</button>
              </div>
              <div style="display:flex;justify-content:space-between;padding:0 20px;font-size:15px;font-weight:800"><span>Paiement en attente</span><span style="font-weight:500;font-size:13px">Voir tout ›</span></div>
              <div style="margin:0 20px;background:#FFFFFF;border-radius:18px;box-shadow:0 1px 3px rgba(14,74,58,.08);padding:14px;display:flex;align-items:center;gap:12px"><span style="width:42px;height:42px;border-radius:50%;background:#E4F3EC;display:flex;align-items:center;justify-content:center;flex:none">▤</span><div style="flex:1;min-width:0"><div style="font-size:13px;font-weight:800">Résidence Les Palmiers</div><div style="font-size:10px;color:#6B7570">Solde · échue le 25 sept. 2026</div><div style="display:flex;align-items:center;gap:6px;margin-top:4px"><strong style="font-size:15px">46 000 FCFA</strong><span style="background:#FDE3E1;color:#C0392B;font-size:10px;font-weight:700;padding:2px 6px;border-radius:6px">En retard</span></div></div></div>
            </div>
            <button onClick="{{ startResa }}" style="position:absolute;right:16px;bottom:14px;background:#F2B21E;color:#0E4A3A;border:none;border-radius:30px;padding:14px 20px;font-size:15px;font-weight:800;cursor:pointer;box-shadow:0 10px 20px -8px rgba(242,178,30,.8)" style-hover="background:#FAB82A">+ Réserver</button>
          </sc-if>

          <sc-if value="{{ s1 }}" hint-placeholder-val="{{ false }}">
            <div style="flex:1;overflow-y:auto;padding:8px 18px 16px;display:flex;flex-direction:column;gap:14px">
              <div style="display:flex;align-items:center;gap:8px"><button onClick="{{ back }}" style="width:34px;height:34px;border:none;background:transparent;font-size:24px;color:#0E4A3A;cursor:pointer;padding:0;line-height:1">‹</button><span style="font-size:20px;font-weight:800">Nouvelle réservation</span></div>
              <div style="display:flex;align-items:center;gap:8px;font-size:13px;font-weight:700"><span style="width:26px;height:26px;border-radius:50%;background:#0E4A3A;color:#FFFFFF;display:flex;align-items:center;justify-content:center;font-size:12px">1</span><span style="color:#0E4A3A">Chantier</span><span style="flex:1;height:2px;background:#DDE3DF"></span><span style="width:26px;height:26px;border-radius:50%;background:#E8ECE9;color:#6B7570;display:flex;align-items:center;justify-content:center;font-size:12px">2</span><span style="color:#6B7570">Besoins</span></div>
              <div><div style="font-size:18px;font-weight:800">Pour quel chantier ?</div><div style="font-size:13px;color:#6B7570;margin-top:4px;line-height:1.4">Sélectionnez le chantier pour lequel vous recherchez des ouvriers.</div></div>
              <sc-for list="{{ chantiers }}" as="c" hint-placeholder-count="4">
                <button onClick="{{ c.open }}" style="display:flex;align-items:center;gap:12px;text-align:left;background:#FFFFFF;border:2px solid {{ c.border }};border-radius:16px;padding:8px 12px 8px 8px;cursor:pointer;color:#0E4A3A" style-hover="border-color:#0E4A3A">
                  <span style="width:64px;height:52px;border-radius:10px;flex:none;background:repeating-linear-gradient(135deg,#E8ECE9 0 8px,#F1F4F2 8px 16px);overflow:hidden;display:block"><img src="{{ c.photo }}" alt="" style="width:100%;height:100%;object-fit:cover;display:{{ c.photoDisplay }}"></span>
                  <span style="flex:1;min-width:0;display:flex;flex-direction:column;gap:3px"><span style="font-size:14px;font-weight:800">{{ c.name }}</span><span style="font-size:12px;color:#6B7570">{{ c.city }}</span><span style="align-self:flex-start;font-size:10px;font-weight:700;padding:3px 8px;border-radius:8px;background:{{ c.chipBg }};color:{{ c.chipFg }}">{{ c.status }}</span></span>
                  <span style="font-size:20px;color:#6B7570">›</span>
                </button>
              </sc-for>
            </div>
          </sc-if>

          <sc-if value="{{ s2 }}" hint-placeholder-val="{{ false }}">
            <div style="flex:1;overflow-y:auto;padding:8px 18px 12px;display:flex;flex-direction:column;gap:12px">
              <div style="display:flex;align-items:center;gap:8px"><button onClick="{{ back }}" style="width:34px;height:34px;border:none;background:transparent;font-size:24px;color:#0E4A3A;cursor:pointer;padding:0;line-height:1">‹</button><span style="font-size:20px;font-weight:800">Nouvelle réservation</span></div>
              <div style="display:flex;align-items:center;gap:8px;font-size:13px;font-weight:700"><span style="width:26px;height:26px;border-radius:50%;background:#0E4A3A;color:#FFFFFF;display:flex;align-items:center;justify-content:center;font-size:12px">✓</span><span style="color:#6B7570">Chantier</span><span style="flex:1;height:2px;background:#0E4A3A"></span><span style="width:26px;height:26px;border-radius:50%;background:#0E4A3A;color:#FFFFFF;display:flex;align-items:center;justify-content:center;font-size:12px">2</span><span style="color:#0E4A3A">Besoins</span></div>
              <div class="card" style="padding:10px 14px 10px 10px;display:flex;align-items:center;gap:10px"><span style="width:48px;height:40px;border-radius:8px;flex:none;background:repeating-linear-gradient(135deg,#E8ECE9 0 8px,#F1F4F2 8px 16px);overflow:hidden;display:block"><img src="{{ cur.photo }}" alt="" style="width:100%;height:100%;object-fit:cover;display:{{ cur.photoDisplay }}"></span><span style="flex:1;min-width:0"><span style="display:block;font-size:13px;font-weight:800">{{ cur.name }}</span><span style="display:block;font-size:11px;color:#6B7570">{{ cur.city }} · {{ cur.status }}</span></span><button onClick="{{ change }}" style="border:none;background:none;color:#0E4A3A;font-weight:700;font-size:13px;cursor:pointer">Changer</button></div>
              <div class="card" style="padding:14px;display:flex;flex-direction:column;gap:8px"><span style="font-size:15px;font-weight:800">Dates de la mission</span><div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;font-size:11px;color:#6B7570"><span>Début<span style="display:block;margin-top:4px;border:1.5px solid #DDE3DF;border-radius:10px;padding:9px 10px;font-size:13px;color:#14261F">30/09/2026</span></span><span>Fin<span style="display:block;margin-top:4px;border:1.5px solid #DDE3DF;border-radius:10px;padding:9px 10px;font-size:13px;color:#14261F">04/10/2026</span></span></div><div style="display:flex;justify-content:space-between;font-size:11px;color:#6B7570">Durée<strong style="color:#2FB57E">5 jours · 30 sept. au 4 oct.</strong></div></div>
              <div class="card" style="padding:14px;display:flex;flex-direction:column;gap:4px">
                <div style="display:flex;justify-content:space-between;align-items:baseline"><span style="font-size:15px;font-weight:800">Besoins en ouvriers</span><span style="font-size:12px;font-weight:700;color:#2FB57E">{{ totalLabel }}</span></div>
                <div style="font-size:11px;color:#6B7570;line-height:1.4;margin-bottom:4px">Indiquez le nombre d’ouvriers par métier.</div>
                <sc-for list="{{ needsList }}" as="n" hint-placeholder-count="5">
                  <div style="display:flex;flex-direction:column;gap:4px;padding:8px 0;border-top:1px solid #EEF1EF">
                    <div style="display:flex;align-items:center;gap:8px"><span style="flex:1;min-width:0"><span style="display:block;font-size:13px;font-weight:700">{{ n.metier }}</span><span style="display:block;font-size:10px;color:#6B7570">Tarif moyen : {{ n.tarif }} / jour</span></span>
                      <button onClick="{{ n.dec }}" style="width:30px;height:30px;border-radius:50%;border:1.5px solid #DDE3DF;background:#FFFFFF;color:#6B7570;font-size:16px;cursor:pointer;line-height:1">−</button>
                      <span style="width:20px;text-align:center;font-size:15px;font-weight:800">{{ n.count }}</span>
                      <button onClick="{{ n.inc }}" style="width:30px;height:30px;border-radius:50%;border:none;background:#0E4A3A;color:#FFFFFF;font-size:16px;cursor:pointer;line-height:1">+</button></div>
                    <span style="font-size:11px;font-weight:700">Choisir des {{ n.plural }} disponibles ›</span>
                  </div>
                </sc-for>
              </div>
            </div>
            <div style="padding:10px 16px 12px;background:#FFFFFF;border-top:1px solid #EEF1EF;display:flex;flex-direction:column;gap:6px"><div style="display:flex;justify-content:space-between;font-size:11px;color:#6B7570">Estimation main-d’œuvre<strong style="color:#0E4A3A">{{ estimate }}</strong></div><button onClick="{{ launch }}" style="width:100%;background:{{ launchBg }};color:#0E4A3A;border:none;border-radius:14px;padding:14px;font-size:14px;font-weight:800;cursor:pointer">Lancer la recherche · {{ totalLabel }}</button></div>
          </sc-if>

          <sc-if value="{{ s3 }}" hint-placeholder-val="{{ false }}">
            <div style="flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:22px;padding:24px;text-align:center">
              <div style="position:relative;width:170px;height:170px">
                <div data-radar="1" style="position:absolute;inset:0;border-radius:50%;border:2px solid #2FB57E"></div>
                <div data-radar="2" style="position:absolute;inset:0;border-radius:50%;border:2px solid #2FB57E"></div>
                <div data-radar="3" style="position:absolute;inset:0;border-radius:50%;border:2px solid #2FB57E"></div>
                <div style="position:absolute;left:50%;top:50%;width:64px;height:64px;margin:-32px 0 0 -32px;border-radius:50%;background:#0E4A3A;display:flex;align-items:center;justify-content:center"><img src="assets/mono-couleur.webp" alt="" style="width:26px;display:block"></div>
              </div>
              <div><div style="font-size:18px;font-weight:800">Recherche d’ouvriers</div><div style="font-size:13px;color:#6B7570;margin-top:6px;line-height:1.5">{{ totalLabel }} · {{ cur.city }}<br>Contact des profils disponibles…</div></div>
            </div>
          </sc-if>

          <sc-if value="{{ s4 }}" hint-placeholder-val="{{ false }}">
            <div style="flex:1;overflow-y:auto;padding:8px 18px 12px;display:flex;flex-direction:column;gap:12px">
              <div style="display:flex;align-items:center;gap:8px"><button onClick="{{ back }}" style="width:34px;height:34px;border:none;background:transparent;font-size:24px;color:#0E4A3A;cursor:pointer;padding:0;line-height:1">‹</button><span style="font-size:20px;font-weight:800">Offre reçue</span></div>
              <div class="card" style="padding:14px;display:flex;flex-direction:column;gap:10px">
                <span style="font-size:15px;font-weight:800">Équipe proposée · {{ totalLabel }}</span>
                <sc-for list="{{ team }}" as="w" hint-placeholder-count="3">
                  <div style="display:flex;align-items:center;gap:10px"><img src="{{ w.photo }}" alt="" style="width:40px;height:40px;border-radius:50%;object-fit:cover;display:block;flex:none"><span style="flex:1;min-width:0"><span style="display:block;font-size:13px;font-weight:800">{{ w.metier }}</span><span style="display:block;font-size:11px;color:#6B7570">{{ w.name }} · {{ w.tarif }}/j</span></span></div>
                </sc-for>
                <span style="font-size:12px;font-weight:700;color:#2FB57E">✓ Identités et compétences vérifiées</span>
              </div>
              <div class="card" style="padding:14px;display:flex;flex-direction:column">
                <span style="font-size:15px;font-weight:800;margin-bottom:6px">Conditions</span>
                <div style="display:flex;justify-content:space-between;padding:7px 0;border-bottom:1px solid #EEF1EF;font-size:12px"><span style="color:#6B7570">Chantier</span><strong>{{ cur.name }}</strong></div>
                <div style="display:flex;justify-content:space-between;padding:7px 0;border-bottom:1px solid #EEF1EF;font-size:12px"><span style="color:#6B7570">Durée</span><strong>5 jours</strong></div>
                <div style="display:flex;justify-content:space-between;padding:7px 0;border-bottom:1px solid #EEF1EF;font-size:12px"><span style="color:#6B7570">Main-d’œuvre</span><strong>{{ perDay }} / jour</strong></div>
                <div style="display:flex;justify-content:space-between;padding:7px 0;font-size:12px"><span style="color:#6B7570">Total</span><strong>{{ estimate }}</strong></div>
                <div style="display:flex;background:#E4F3EC;border-radius:14px;padding:12px;margin-top:6px"><div style="flex:1;border-right:1px solid #C6E3D6"><div style="font-size:11px;color:#6B7570">Acompte (30 %)</div><div style="font-size:20px;font-weight:900">{{ deposit }}</div></div><div style="padding-left:12px"><div style="font-size:11px;color:#6B7570">Solde</div><div style="font-size:14px;font-weight:800">{{ balance }}</div></div></div>
              </div>
              <div class="card" style="padding:14px;display:flex;flex-direction:column;gap:10px">
                <span style="font-size:15px;font-weight:800">Suivi de la réservation</span>
                <div style="display:flex;gap:10px;font-size:12px"><span style="width:20px;height:20px;border-radius:50%;background:#0E4A3A;color:#FFFFFF;font-size:10px;display:flex;align-items:center;justify-content:center;flex:none">✓</span><span style="flex:1"><strong>Demande publiée</strong><br><span style="color:#6B7570">Besoins transmis aux ouvriers</span></span><span style="color:#6B7570">Fait</span></div>
                <div style="display:flex;gap:10px;font-size:12px"><span style="width:20px;height:20px;border-radius:50%;background:#0E4A3A;color:#FFFFFF;font-size:10px;display:flex;align-items:center;justify-content:center;flex:none">✓</span><span style="flex:1"><strong>Recherche d’ouvriers</strong><br><span style="color:#6B7570">Contact des profils disponibles</span></span><span style="color:#6B7570">Fait</span></div>
                <div style="display:flex;gap:10px;font-size:12px"><span style="width:20px;height:20px;border-radius:50%;border:6px solid #0E4A3A;box-sizing:border-box;flex:none"></span><span style="flex:1"><strong>Offre reçue</strong><br><span style="color:#6B7570">Acceptez ou refusez l’offre</span></span><span style="color:#2F6FD6;font-weight:700">En cours</span></div>
                <div style="display:flex;gap:10px;font-size:12px"><span style="width:20px;height:20px;border-radius:50%;border:2px solid #DDE3DF;flex:none"></span><span style="flex:1"><strong>Acompte et confirmation</strong><br><span style="color:#6B7570">Réservation validée après paiement</span></span></div>
              </div>
            </div>
            <div style="padding:10px 16px 12px;display:grid;grid-template-columns:1fr 1.7fr;gap:8px;background:#F5F7F5"><button onClick="{{ back }}" style="border:2px solid #0E4A3A;background:#FFFFFF;color:#0E4A3A;border-radius:14px;padding:12px;font-size:13px;font-weight:800;cursor:pointer">Refuser</button><button onClick="{{ confirm }}" style="border:none;background:#F2B21E;color:#0E4A3A;border-radius:14px;padding:12px;font-size:13px;font-weight:800;cursor:pointer" style-hover="background:#FAB82A">Accepter et payer l’acompte</button></div>
          </sc-if>

          <sc-if value="{{ s5 }}" hint-placeholder-val="{{ false }}">
            <div style="flex:1;display:flex;flex-direction:column;justify-content:center;gap:16px;padding:28px">
              <span style="width:72px;height:72px;border-radius:50%;background:#2FB57E;color:#FFFFFF;display:flex;align-items:center;justify-content:center;font-size:34px;font-weight:900">✓</span>
              <div style="font-size:26px;font-weight:900;line-height:1.05">Réservation<br>confirmée</div>
              <div style="font-size:14px;line-height:1.55;color:#6B7570">Acompte de <strong style="color:#0E4A3A">{{ deposit }}</strong> payé. {{ totalLabel }} sont attendus le 30 sept. sur <strong style="color:#0E4A3A">{{ cur.name }}</strong>.</div>
              <div style="background:#E4F3EC;border-radius:14px;padding:12px 14px;font-size:12px;line-height:1.5"><strong>Paiement sécurisé</strong><br>Le solde de {{ balance }} est versé après validation du travail.</div>
              <button onClick="{{ restart }}" style="border:none;background:#0E4A3A;color:#FFFFFF;border-radius:14px;padding:14px;font-size:14px;font-weight:800;cursor:pointer">Retour à l’accueil</button>
            </div>
          </sc-if>

          </div>
          <div style="display:grid;grid-template-columns:repeat(5,1fr);border-top:1px solid #EEF1EF;background:#FFFFFF;padding:8px 4px 18px;font-size:10px;text-align:center;color:#6B7570;flex:none">
            <span style="display:flex;flex-direction:column;align-items:center;gap:3px;font-weight:{{ navHomeW }};color:{{ navHomeC }}"><span style="width:42px;height:24px;border-radius:12px;background:{{ navHomeBg }};display:flex;align-items:center;justify-content:center;font-size:13px">⌂</span>Accueil</span>
            <span style="display:flex;flex-direction:column;align-items:center;gap:3px"><span style="height:24px;display:flex;align-items:center;font-size:13px">◬</span>Chantiers</span>
            <span style="display:flex;flex-direction:column;align-items:center;gap:3px;font-weight:{{ navResaW }};color:{{ navResaC }}"><span style="width:42px;height:24px;border-radius:12px;background:{{ navResaBg }};display:flex;align-items:center;justify-content:center;font-size:13px">▣</span>Réservations</span>
            <span style="display:flex;flex-direction:column;align-items:center;gap:3px"><span style="height:24px;display:flex;align-items:center;font-size:13px">▭</span>Paiements</span>
            <span style="display:flex;flex-direction:column;align-items:center;gap:3px"><span style="height:24px;display:flex;align-items:center;font-size:13px">◯</span>Profil</span>
          </div>
        </div>
      </div>
    </div>
  </section>

  <section id="solution" data-screen-label="02 La solution" style="max-width:1240px;margin:0 auto;padding:clamp(56px,6vw,88px) clamp(20px,4vw,48px)">
    <div data-reveal="" style="display:flex;justify-content:space-between;align-items:flex-end;gap:24px;flex-wrap:wrap">
      <div><div class="kicker" style="color:#1E6B4F">La solution</div><h2 class="h-sec">Recruter, suivre, payer.<br>Au même endroit.</h2></div>
      <p style="margin:0;max-width:380px;font-size:16px;line-height:1.6;color:#5B645F">Six outils reliés entre eux : chaque information saisie sert à toute l’équipe.</p>
    </div>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,380px),1fr));gap:clamp(24px,3vw,40px);margin-top:48px;align-items:stretch">
      <div style="display:flex;flex-direction:column;border-top:2px solid #14261F">
        <sc-for list="{{ features }}" as="f" hint-placeholder-count="5">
          <button onClick="{{ f.select }}" style="display:grid;grid-template-columns:44px minmax(0,1fr);gap:14px;text-align:left;padding:20px 16px;border:none;border-bottom:1px solid #D9D6CD;background:{{ f.bg }};color:{{ f.fg }};cursor:pointer;transition:background .25s,color .25s">
            <span style="font-family:'JetBrains Mono',monospace;font-size:13px;padding-top:4px;color:{{ f.num }}">{{ f.n }}</span>
            <span style="display:flex;flex-direction:column;gap:6px"><span style="display:flex;align-items:center;gap:10px;flex-wrap:wrap"><span style="font-size:clamp(18px,1.7vw,22px);font-weight:800">{{ f.title }}</span><sc-if value="{{ f.isCore }}" hint-placeholder-val="{{ false }}"><span style="background:#FAB82A;color:#14261F;font-family:'JetBrains Mono',monospace;font-size:10px;font-weight:500;letter-spacing:.08em;padding:3px 6px">CŒUR DE MÉTIER</span></sc-if></span><span style="font-size:15px;line-height:1.55;opacity:.85">{{ f.text }}</span></span>
          </button>
        </sc-for>
      </div>
      <div style="background:#E8E6DF;padding:clamp(20px,3vw,40px);display:flex;align-items:center;justify-content:center;min-height:540px;position:relative;overflow:hidden">
        <div style="position:absolute;right:-70px;top:-70px;width:220px;height:220px;border-radius:50%;border:38px solid #FAB82A"></div>
        <sc-if value="{{ isChrono }}" hint-placeholder-val="{{ false }}"><div style="position:relative;width:min(100%,300px)"><img src="assets/app-chronologie.webp" loading="lazy" decoding="async" alt="" style="width:100%;display:block;border-radius:44px;filter:blur(5px) brightness(.9);opacity:.85"><img ref="{{ chronoRef }}" loading="lazy" decoding="async" src="assets/app-chrono-card.webp" alt="Chronologie des étapes du chantier" style="position:absolute;left:-10%;width:120%;top:19%;display:block;border-radius:20px;box-shadow:0 40px 70px -25px rgba(20,38,31,.6)"></div></sc-if>
        <sc-if value="{{ hasShot }}" hint-placeholder-val="{{ true }}"><img src="{{ activeShot }}" loading="lazy" decoding="async" alt="Écran de l’application O’CHANTIER" style="position:relative;width:min(100%,310px);display:block;border-radius:44px;box-shadow:0 30px 60px -30px rgba(20,38,31,.7)"></sc-if>
      </div>
    </div>
  </section>

  <section id="paiement" data-screen-label="03 Paiement" style="background:#3EB884">
    <div style="max-width:1240px;margin:0 auto;padding:clamp(56px,6vw,88px) clamp(20px,4vw,48px);display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,440px),1fr));gap:clamp(40px,5vw,72px);align-items:center">
      <div data-reveal="" style="display:flex;flex-direction:column;gap:22px">
        <div class="kicker">Paiement flexible &amp; sécurisé</div>
        <h2 class="h-sec" style="margin:0">Payé pour le travail fait. Ni avant, ni après.</h2>
        <p style="margin:0;font-size:17px;line-height:1.6;max-width:520px">Vous déposez les fonds au lancement de la mission. Ils restent sécurisés jusqu’à la validation du travail, puis l’ouvrier est payé immédiatement. Vous êtes protégé, et lui aussi.</p>
        <div style="display:flex;gap:10px;flex-wrap:wrap">
          <span style="border:2px solid #14261F;padding:10px 14px;font-weight:800;font-size:14px">Acompte de 30 %</span>
          <span style="border:2px solid #14261F;padding:10px 14px;font-weight:800;font-size:14px">En 2 ou 3 fois</span>
          <span style="border:2px solid #14261F;padding:10px 14px;font-weight:800;font-size:14px">Solde après validation</span>
        </div>
      </div>
      <div data-reveal="" style="display:flex;flex-direction:column;gap:0;background:#14261F;color:#F8F7F3;padding:clamp(24px,3vw,36px)">
        <div style="display:grid;grid-template-columns:56px minmax(0,1fr);gap:18px;padding-bottom:24px;border-bottom:1px solid #2A3D35"><span style="width:56px;height:56px;border-radius:50%;border:3px solid #FAB82A;display:flex;align-items:center;justify-content:center;font-weight:900;color:#FAB82A">1</span><div><div style="font-size:20px;font-weight:800">Fonds déposés</div><div style="font-size:14px;line-height:1.5;color:#CFEDE0;margin-top:4px">Le montant est réservé sur un compte de séquestre.</div></div></div>
        <div style="display:grid;grid-template-columns:56px minmax(0,1fr);gap:18px;padding:24px 0;border-bottom:1px solid #2A3D35"><span style="width:56px;height:56px;border-radius:50%;border:3px solid #FAB82A;display:flex;align-items:center;justify-content:center;font-weight:900;color:#FAB82A">2</span><div><div style="font-size:20px;font-weight:800">Travail validé</div><div style="font-size:14px;line-height:1.5;color:#CFEDE0;margin-top:4px">Vous validez à partir des présences et des étapes réalisées.</div></div></div>
        <div style="display:grid;grid-template-columns:56px minmax(0,1fr);gap:18px;padding-top:24px"><span style="width:56px;height:56px;border-radius:50%;background:#FAB82A;color:#14261F;display:flex;align-items:center;justify-content:center;font-weight:900">3</span><div><div style="font-size:20px;font-weight:800">Ouvrier payé</div><div style="font-size:14px;line-height:1.5;color:#CFEDE0;margin-top:4px">Le virement part tout de suite et la facture est générée automatiquement.</div></div></div>
      </div>
    </div>
  </section>

  <section id="tarifs" data-screen-label="04 Tarifs" style="max-width:1240px;margin:0 auto;padding:clamp(56px,6vw,88px) clamp(20px,4vw,48px)">
    <div data-reveal="" style="display:flex;justify-content:space-between;align-items:flex-end;gap:24px;flex-wrap:wrap">
      <div><div class="kicker" style="color:#1E6B4F">Abonnements</div><h2 class="h-sec">Une formule pour chaque équipe.</h2></div>
      <p style="margin:0;max-width:420px;font-size:16px;line-height:1.6;color:#5B645F">Avec une formule Équipe ou Pro, invitez vos collaborateurs par SMS ou e-mail et attribuez-leur un rôle : chef de chantier, technicien, comptable ou observateur.</p>
    </div>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,300px),1fr));gap:20px;margin-top:44px;align-items:stretch">
      <div data-reveal="" style="position:relative;background:#FFFFFF;color:#14261F;padding:28px;display:flex;flex-direction:column;gap:18px;border:2px solid #14261F">
        
        <div><div style="font-size:26px;font-weight:900;text-transform:uppercase">Solo</div><div style="font-size:14px;opacity:.75;margin-top:4px">1 utilisateur</div></div>
        <div style="display:flex;align-items:baseline;gap:8px;flex-wrap:wrap"><span style="font-size:clamp(30px,3vw,40px);font-weight:900;color:#14261F">Gratuit</span><span style="font-size:14px;opacity:.75">pour toujours</span></div>
        <div style="display:flex;flex-direction:column;gap:10px;border-top:1px solid #E8E6DF;padding-top:16px;font-size:15px;line-height:1.4"><div class="tick"><span><strong>Gestion jusqu’à 5 chantiers</strong></span></div><div class="tick"><span>Propriétaire uniquement</span></div><div class="tick"><span>Chantiers et réservations</span></div><div class="tick"><span>Pointage jusqu’à 300</span></div><div class="tick"><span>Paiement en 2 fois · sans rapport global</span></div></div>
        <a href="#telecharger" style="margin-top:auto;text-align:center;padding:16px;font-weight:800;background:transparent;color:#14261F;border:2px solid #14261F" style-hover="background:#14261F;color:#F8F7F3">Commencer gratuitement</a>
      </div>
      <div data-reveal="" style="position:relative;background:#14261F;color:#F8F7F3;padding:28px;display:flex;flex-direction:column;gap:18px;border:2px solid #14261F">
        <span style="position:absolute;right:20px;top:-14px;background:#FAB82A;color:#14261F;font-size:12px;font-weight:800;padding:6px 10px">Le plus choisi</span>
        <div><div style="font-size:26px;font-weight:900;text-transform:uppercase">Équipe</div><div style="font-size:14px;opacity:.75;margin-top:4px">Jusqu’à 5 utilisateurs</div></div>
        <div style="display:flex;align-items:baseline;gap:8px;flex-wrap:wrap"><span style="font-size:clamp(30px,3vw,40px);font-weight:900;color:#FAB82A">99 900 FCFA</span><span style="font-size:14px;opacity:.75">par mois</span></div>
        <div style="display:flex;flex-direction:column;gap:10px;border-top:1px solid #2A3D35;padding-top:16px;font-size:15px;line-height:1.4"><div class="tick"><span><strong>Gestion jusqu’à 25 chantiers</strong></span></div><div class="tick"><span>Jusqu’à 5 utilisateurs · rôles clés</span></div><div class="tick"><span>Pointage jusqu’à 500</span></div><div class="tick"><span>Gestion chronologique du chantier</span></div><div class="tick"><span>Journal d’activité</span></div><div class="tick"><span>Paiement flexible, adapté à la durée de votre chantier</span></div><div class="tick"><span>Rapport global de fin de chantier</span></div></div>
        <a href="#telecharger" style="margin-top:auto;text-align:center;padding:16px;font-weight:800;background:#FAB82A;color:#14261F;border:2px solid #FAB82A" style-hover="background:#FFFFFF;color:#14261F">Choisir Équipe</a>
      </div>
      <div data-reveal="" style="position:relative;background:#FFFFFF;color:#14261F;padding:28px;display:flex;flex-direction:column;gap:18px;border:2px solid #14261F">
        
        <div><div style="font-size:26px;font-weight:900;text-transform:uppercase">Pro</div><div style="font-size:14px;opacity:.75;margin-top:4px">Jusqu’à 15 utilisateurs</div></div>
        <div style="display:flex;align-items:baseline;gap:8px;flex-wrap:wrap"><span style="font-size:clamp(30px,3vw,40px);font-weight:900;color:#14261F">149 900 FCFA</span><span style="font-size:14px;opacity:.75">par mois</span></div>
        <div style="display:flex;flex-direction:column;gap:10px;border-top:1px solid #E8E6DF;padding-top:16px;font-size:15px;line-height:1.4"><div class="tick"><span><strong>Gestion illimitée de chantiers</strong></span></div><div class="tick"><span>Jusqu’à 15 utilisateurs · rôles clés</span></div><div class="tick"><span>Pointage illimité</span></div><div class="tick"><span>Gestion chronologique du chantier</span></div><div class="tick"><span>Journal d’activité</span></div><div class="tick"><span>Paiement flexible, adapté à la durée de votre chantier</span></div><div class="tick"><span>Rapport global de fin de chantier</span></div></div>
        <a href="#telecharger" style="margin-top:auto;text-align:center;padding:16px;font-weight:800;background:transparent;color:#14261F;border:2px solid #14261F" style-hover="background:#14261F;color:#F8F7F3">Choisir Pro</a>
      </div>
    </div>
  </section>

  <section id="carte" data-screen-label="05 Carte des chantiers" style="background:#14261F;color:#F8F7F3">
    <div style="max-width:1240px;margin:0 auto;padding:clamp(56px,6vw,88px) clamp(20px,4vw,48px);display:flex;flex-direction:column;gap:32px">
      <div data-reveal="" style="display:flex;justify-content:space-between;align-items:flex-end;gap:24px;flex-wrap:wrap">
        <div><div class="kicker" style="color:#FAB82A">Vous êtes ouvrier ?</div><h2 class="h-sec">Des chantiers recrutent près de chez vous.</h2></div>
        <div style="display:flex;flex-direction:column;gap:14px;max-width:400px"><p style="margin:0;font-size:16px;line-height:1.6;color:#CFEDE0">Filtrez par métier, localisez-vous et postulez depuis la carte. Les candidatures sont étudiées par l’équipe O’CHANTIER, qui vous recontacte pour vous affecter aux chantiers.</p><button onClick="{{ openForm }}" style="align-self:flex-start;background:#FAB82A;color:#14261F;border:none;padding:16px 24px;font-size:15px;font-weight:900;text-transform:uppercase;cursor:pointer" style-hover="background:#FFFFFF">Devenir ouvrier →</button></div>
      </div>
      <div data-reveal="" style="height:620px;background:#E8E6DF;border:3px solid #FAB82A"><iframe src="carte-chantiers.html" title="Carte des chantiers qui recrutent" loading="lazy" style="width:100%;height:100%;border:0;display:block"></iframe></div>
    </div>
  </section>

  <section id="telecharger" data-screen-label="06 Application" style="background:#1E6B4F;color:#FFFFFF;overflow:hidden">
    <div style="max-width:1240px;margin:0 auto;padding:clamp(56px,6vw,88px) clamp(20px,4vw,48px);display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,440px),1fr));gap:clamp(40px,5vw,72px);align-items:center">
      <div data-reveal="" style="display:flex;flex-direction:column;gap:22px">
        <div class="kicker" style="color:#FAB82A">Application promoteurs &amp; techniciens</div>
        <h2 class="h-sec" style="margin:0">Téléchargez l’application O’CHANTIER.</h2>
        <p style="margin:0;font-size:17px;line-height:1.6;max-width:500px;color:#ECF8F3">L’application des promoteurs immobiliers et des techniciens de chantier. Réservez des ouvriers qualifiés, suivez les présences et l’avancement, payez en toute sécurité. Disponible sur iPhone et Android.</p>
        <div style="display:flex;gap:10px;flex-wrap:wrap">
  <a href="#telecharger" style="display:flex;flex-direction:column;justify-content:center;background:#000000;color:#FFFFFF;border:1px solid #5B645F;border-radius:10px;padding:8px 18px;min-width:150px;line-height:1.1" style-hover="background:#1E6B4F;color:#FFFFFF"><span style="font-size:11px">Télécharger dans l’</span><span style="font-size:19px;font-weight:700">App Store</span></a>
  <a href="#telecharger" style="display:flex;flex-direction:column;justify-content:center;background:#000000;color:#FFFFFF;border:1px solid #5B645F;border-radius:10px;padding:8px 18px;min-width:150px;line-height:1.1" style-hover="background:#1E6B4F;color:#FFFFFF"><span style="font-size:11px">DISPONIBLE SUR</span><span style="font-size:19px;font-weight:700">Google Play</span></a>
</div>
        <div style="display:flex;align-items:center;gap:16px;margin-top:6px">
          <img src="assets/qr-code.png" loading="lazy" decoding="async" alt="QR code de téléchargement de l’application O’CHANTIER" style="width:120px;height:120px;object-fit:contain;background:#FFFFFF;display:block;flex:none">
          <span style="font-size:14px;line-height:1.5;color:#CFEDE0">Scannez le code avec votre téléphone pour installer l’application.</span>
        </div>
      </div>
      <div data-reveal="" style="position:relative;height:560px;display:flex;justify-content:center;align-items:center">
        <div style="position:absolute;width:420px;height:420px;border-radius:50%;border:56px solid #FAB82A;opacity:.95"></div>
        <img src="assets/app-accueil.webp" loading="lazy" decoding="async" width="620" height="1288" alt="Accueil de l’application O’CHANTIER" style="position:relative;width:250px;height:auto;display:block;border-radius:40px;transform:translateX(-80px) rotate(-7deg);box-shadow:0 40px 60px -30px rgba(0,0,0,.6)">
        <img src="assets/app-reservations.webp" loading="lazy" decoding="async" width="620" height="1311" alt="Réservations dans l’application O’CHANTIER" style="position:absolute;width:250px;height:auto;display:block;border-radius:40px;transform:translateX(100px) translateY(30px) rotate(6deg);box-shadow:0 40px 60px -30px rgba(0,0,0,.6)">
      </div>
    </div>
  </section>

  <section id="contact" data-screen-label="07 Nous contacter" style="background:#F8F7F3">
    <div style="max-width:1240px;margin:0 auto;padding:clamp(56px,6vw,88px) clamp(20px,4vw,48px);display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,420px),1fr));gap:clamp(32px,5vw,64px);align-items:start">
      <div data-reveal="" style="display:flex;flex-direction:column;gap:20px">
        <div class="kicker" style="color:#1E6B4F">Nous contacter</div>
        <h2 class="h-sec" style="margin:0">Une question ? Parlons de votre chantier.</h2>
        <p style="margin:0;font-size:16px;line-height:1.6;color:#5B645F;max-width:460px">Votre message est transmis directement à l’équipe O’CHANTIER. Nous répondons sous 24 h ouvrées.</p>
        <div style="display:flex;flex-direction:column;border-top:2px solid #14261F;max-width:460px">
          <div style="display:flex;flex-direction:column;gap:4px;padding:14px 0;border-bottom:1px solid #D9D6CD"><span style="font-family:'JetBrains Mono',monospace;font-size:10px;letter-spacing:.12em;text-transform:uppercase;color:#1E6B4F">Téléphone</span><span style="font-size:16px;font-weight:700">+225 07 00 00 00 00</span></div>
          <div style="display:flex;flex-direction:column;gap:4px;padding:14px 0;border-bottom:1px solid #D9D6CD"><span style="font-family:'JetBrains Mono',monospace;font-size:10px;letter-spacing:.12em;text-transform:uppercase;color:#1E6B4F">E-mail</span><span style="font-size:16px;font-weight:700">contact@ochantier.ci</span></div>
          <div style="display:flex;flex-direction:column;gap:4px;padding:14px 0;border-bottom:1px solid #D9D6CD"><span style="font-family:'JetBrains Mono',monospace;font-size:10px;letter-spacing:.12em;text-transform:uppercase;color:#1E6B4F">Adresse</span><span style="font-size:16px;font-weight:700">Cocody, Abidjan · Côte d’Ivoire</span></div>
          <div style="display:flex;flex-direction:column;gap:4px;padding:14px 0;border-bottom:1px solid #D9D6CD"><span style="font-family:'JetBrains Mono',monospace;font-size:10px;letter-spacing:.12em;text-transform:uppercase;color:#1E6B4F">Horaires</span><span style="font-size:16px;font-weight:700">Lun. – Sam. · 8 h – 18 h</span></div>
        </div>
      </div>
      <div data-reveal="" style="background:#FFFFFF;border:2px solid #14261F;box-shadow:10px 10px 0 #14261F">
        <sc-if value="{{ cNotSent }}" hint-placeholder-val="{{ true }}">
          <form onSubmit="{{ cSubmit }}" style="padding:clamp(20px,3vw,32px);display:flex;flex-direction:column;gap:16px">
            <div class="f-lbl">Vous êtes<div class="f-chips"><sc-for list="{{ cProfils }}" as="p" hint-placeholder-count="5"><button type="button" class="f-chip" aria-pressed="{{ p.on }}" onClick="{{ p.pick }}" style="background:{{ p.bg }};color:{{ p.fg }}">{{ p.label }}</button></sc-for></div></div>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,200px),1fr));gap:14px">
              <label class="f-lbl">Nom complet *<input value="{{ cv.nom }}" onChange="{{ con.nom }}" placeholder="O. Adama" class="f-inp"></label>
              <label class="f-lbl">Entreprise<input value="{{ cv.entreprise }}" onChange="{{ con.entreprise }}" placeholder="Nom de la société" class="f-inp"></label>
              <label class="f-lbl">Téléphone *<input type="tel" value="{{ cv.tel }}" onChange="{{ con.tel }}" placeholder="+225 07 00 00 00 00" class="f-inp"></label>
              <label class="f-lbl">E-mail *<input type="email" value="{{ cv.email }}" onChange="{{ con.email }}" placeholder="vous@entreprise.ci" class="f-inp"></label>
            </div>
            <label class="f-lbl">Objet<select value="{{ cv.objet }}" onChange="{{ con.objet }}" class="f-inp"><option>Demande de démonstration</option><option>Abonnement Équipe ou Pro</option><option>Recrutement d’ouvriers</option><option>Partenariat</option><option>Assistance technique</option><option>Autre demande</option></select></label>
            <label class="f-lbl">Message *<textarea value="{{ cv.message }}" onChange="{{ con.message }}" rows="5" placeholder="Décrivez votre besoin : type de chantier, localisation, nombre d’ouvriers, délais…" class="f-inp"></textarea></label>
            <label style="display:flex;gap:10px;align-items:flex-start;font-size:13px;line-height:1.5;cursor:pointer"><input type="checkbox" checked="{{ cv.consent }}" onChange="{{ con.consent }}" style="width:20px;height:20px;accent-color:#1E6B4F;flex:none;margin-top:1px">J’accepte que mes informations soient utilisées par O’CHANTIER pour traiter ma demande. *</label>
            <sc-if value="{{ cHasErr }}" hint-placeholder-val="{{ false }}"><div class="f-err" role="alert">{{ cErr }}</div></sc-if>
            <button type="submit" style="background:#14261F;color:#F8F7F3;border:none;padding:18px;font-size:15px;font-weight:900;text-transform:uppercase;cursor:pointer;font-family:'Play',sans-serif" style-hover="background:#1E6B4F">{{ cBtn }}</button>
          </form>
        </sc-if>
        <sc-if value="{{ cSent }}" hint-placeholder-val="{{ false }}">
          <div style="padding:clamp(24px,4vw,40px);display:flex;flex-direction:column;gap:16px">
            <span style="width:64px;height:64px;border-radius:50%;background:#3EB884;display:flex;align-items:center;justify-content:center;font-size:30px;font-weight:900">✓</span>
            <div style="font-size:26px;font-weight:900;text-transform:uppercase;line-height:1">Message envoyé</div>
            <div style="background:#F8F7F3;padding:14px 16px;font-size:14px;line-height:1.6"><span style="font-family:'JetBrains Mono',monospace;font-size:11px;color:#1E6B4F">RÉFÉRENCE {{ cRef }}</span><br>{{ cv.objet }} · {{ cv.nom }}</div>
            <p style="margin:0;font-size:15px;line-height:1.6;color:#3B4540">Votre demande a été transmise au Back Office O’CHANTIER. Un conseiller vous recontacte sous 24 h ouvrées au {{ cv.tel }}.</p>
            <button onClick="{{ cReset }}" style="align-self:flex-start;background:transparent;border:2px solid #14261F;padding:12px 20px;font-size:14px;font-weight:800;cursor:pointer">Envoyer un autre message</button>
          </div>
        </sc-if>
      </div>
    </div>
  </section>
</main>

<footer style="background:#14261F;color:#CFEDE0">
  <div style="max-width:1240px;margin:0 auto;padding:48px clamp(20px,4vw,48px) 32px;display:flex;flex-direction:column;gap:40px">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:32px;flex-wrap:wrap">
      <div style="display:flex;flex-direction:column;gap:14px"><img src="assets/logo-couleur.webp" loading="lazy" decoding="async" width="600" height="86" alt="O'CHANTIER" style="width:min(100%,300px);height:auto;display:block"><span style="font-size:14px;color:#9FDBC2">Trouvez vos prochains ouvriers, ici et maintenant.</span></div>
      <div style="display:flex;gap:48px;flex-wrap:wrap;font-size:14px;line-height:2">
        <div style="display:flex;flex-direction:column"><span style="font-family:'JetBrains Mono',monospace;font-size:11px;letter-spacing:.12em;color:#FAB82A">ENTREPRISES</span><a href="#accueil" style="color:#F8F7F3">Démo</a><a href="#solution" style="color:#F8F7F3">La solution</a><a href="#paiement" style="color:#F8F7F3">Paiement</a><a href="#tarifs" style="color:#F8F7F3">Tarifs</a></div>
        <div style="display:flex;flex-direction:column"><span style="font-family:'JetBrains Mono',monospace;font-size:11px;letter-spacing:.12em;color:#FAB82A">OUVRIERS</span><a href="#carte" style="color:#F8F7F3">Chantiers près de vous</a><a href="#carte" onClick="{{ openFormLink }}" style="color:#F8F7F3">Devenir ouvrier</a><a href="#telecharger" style="color:#F8F7F3">Télécharger l’app</a></div>
        <div style="display:flex;flex-direction:column"><span style="font-family:'JetBrains Mono',monospace;font-size:11px;letter-spacing:.12em;color:#FAB82A">CONTACT</span><a href="#contact" style="color:#F8F7F3">Nous contacter</a><span style="color:#F8F7F3">contact@ochantier.ci</span><span style="color:#F8F7F3">00 00 00 00 00</span></div>
      </div>
      <div style="display:flex;flex-direction:column;gap:12px;width:min(100%,380px)">
        <span style="font-family:'JetBrains Mono',monospace;font-size:11px;letter-spacing:.12em;color:#FAB82A">NEWSLETTER</span>
        <span style="font-size:15px;line-height:1.5;color:#F8F7F3">Nouveautés, conseils chantier et offres O’CHANTIER, une fois par mois.</span>
        <sc-if value="{{ nlNotDone }}" hint-placeholder-val="{{ true }}">
          <form onSubmit="{{ nlSubmit }}" style="display:flex;border:2px solid #3EB884">
            <input type="email" value="{{ nlEmail }}" onChange="{{ nlChange }}" placeholder="Votre adresse e-mail" aria-label="Adresse e-mail" style="flex:1;min-width:0;background:transparent;border:none;outline:none;color:#F8F7F3;padding:14px;font-size:15px;font-family:'Play',sans-serif">
            <button type="submit" style="background:#FAB82A;color:#14261F;border:none;padding:0 18px;font-size:14px;font-weight:700;cursor:pointer;font-family:'Play',sans-serif;text-transform:uppercase" style-hover="background:#3EB884">S’abonner</button>
          </form>
        </sc-if>
        <sc-if value="{{ nlErrShow }}" hint-placeholder-val="{{ false }}"><span style="font-size:13px;color:#FAB82A">{{ nlErr }}</span></sc-if>
        <sc-if value="{{ nlDone }}" hint-placeholder-val="{{ false }}"><div style="display:flex;gap:10px;align-items:center;background:#1E6B4F;padding:14px;font-size:14px;color:#F8F7F3"><span style="font-weight:700;color:#FAB82A">✓</span>Merci ! Inscription confirmée pour {{ nlEmail }}.</div></sc-if>
        <span style="font-size:12px;color:#9FDBC2">Désinscription possible à tout moment.</span>
      </div>
    </div>
    <div style="display:flex;justify-content:space-between;gap:16px;flex-wrap:wrap;padding-top:20px;border-top:1px solid #2A3D35;font-family:'JetBrains Mono',monospace;font-size:11px;letter-spacing:.08em">
      <span>© 2026 O’CHANTIER · Mentions légales · CGU</span>
      <button onClick="{{ replay }}" style="background:none;border:none;color:#FAB82A;font-family:'JetBrains Mono',monospace;font-size:11px;letter-spacing:.08em;cursor:pointer;padding:0">↺ REVOIR L’INTRO</button>
    </div>
  </div>
</footer>
</x-dc>
@endverbatim
<script type="text/x-dc" data-dc-script>
class Component extends DCLogic {
  state = { entered: false, gone: false, active: 0, menuOpen: false, w: typeof window !== 'undefined' ? window.innerWidth : 1280, step: 0, ch: 0, needs: null, nlEmail: '', nlErr: '', nlDone: false, cSent: false, cErr: '', cSending: false, cRef: '', cv: { profil: 'Promoteur immobilier', nom: '', entreprise: '', tel: '', email: '', objet: 'Demande de démonstration', message: '', consent: false }, formOpen: false, fstep: 0, ferr: '', target: null, fv: this.blankForm() };
  formRef = React.createRef();
  files = { photo: null, cni: null };
  // Envoie un formulaire à l'API Laravel ; renvoie le JSON ou lève le premier message d'erreur.
  async send(url, body) {
    const token = document.querySelector('meta[name="csrf-token"]');
    const res = await fetch(url, { method: 'POST', body, headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': token ? token.content : '' } });
    const data = await res.json().catch(() => ({}));
    if (!res.ok) {
      const errs = data.errors ? Object.values(data.errors).flat() : [];
      throw new Error(errs[0] || data.message || 'Envoi impossible, réessayez dans un instant.');
    }
    return data;
  }
  toFormData(obj) {
    const fd = new FormData();
    Object.entries(obj).forEach(([k, v]) => {
      if (Array.isArray(v)) v.forEach(x => fd.append(k + '[]', x));
      else if (typeof v === 'boolean') fd.append(k, v ? '1' : '0');
      else if (v !== null && v !== undefined) fd.append(k, v);
    });
    return fd;
  }
  videoRef = React.createRef(); coverStripeRef = React.createRef(); liveDotRef = React.createRef();
  coverContentRef = React.createRef(); sceneRef = React.createRef(); screenRef = React.createRef();
  radarAnims = []; chronoAnims = []; coverAnims = [];
  reduced = typeof window !== 'undefined' && window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  FEATS = [
    { n: '01', title: 'Recrutement à la demande', text: 'Trouvez en quelques heures des ouvriers qualifiés et disponibles. Identité, diplômes, habilitations et assurances sont vérifiés.', isCore: true },
    { n: '02', title: 'Suivi des présences', text: 'Pointage géolocalisé avec photo et signature de l’ouvrier. Taux de présence du jour et global.' },
    { n: '03', title: 'Planification des travaux', text: 'Une chronologie d’étapes à démarrer et à valider. L’avancement du chantier se met à jour automatiquement.' },
    { n: '04', title: 'Gestion d’équipe multi-utilisateurs', text: 'Invitez vos collaborateurs par SMS ou e-mail : administrateur, chef de chantier, technicien, comptable ou observateur.' },
    { n: '05', title: 'Paiement flexible et sécurisé', text: 'Acompte de 30 % à la réservation, puis solde ou paiement en 2 ou 3 fois. Échéances suivies dans l’app.' },
    { n: '06', title: 'Rapport de fin de chantier', text: 'Un document généré automatiquement : durée, présence, étapes, équipe et historique des pointages.' },
  ];
  TARIFS = { 'Maçon': 25000, 'Électricien': 30000, 'Plombier': 20000, 'Peintre': 18000, 'Carreleur': 22000, 'Ferrailleur': 20000 };
  PLURAL = { 'Maçon': 'maçons', 'Électricien': 'électriciens', 'Plombier': 'plombiers', 'Peintre': 'peintres', 'Carreleur': 'carreleurs', 'Ferrailleur': 'ferrailleurs' };
  POOL = {
    'Maçon': ['Yao Koffi', 'Kouassi Serge', 'Diallo Ibrahim', 'Koné Adama'],
    'Électricien': ['Bamba Issa', 'Konan Hervé', 'Touré Aziz'],
    'Plombier': ['Traoré Moussa', 'Kouamé Éric'],
    'Peintre': ['N’Guessan Paul', 'Aka Jean'],
    'Carreleur': ['Coulibaly Sékou', 'Dosso Ali'],
    'Ferrailleur': ['Ouattara Lamine', 'Sanogo Karim'],
  };
  PHOTOS = ['assets/ouvrier-2.webp', 'assets/ouvrier-1.webp', 'assets/ouvrier-3.webp'];
  CHANTIERS = [
    { name: 'Résidence Les Palmiers', city: 'Cocody, Abidjan', status: 'En cours', photo: 'assets/photo-palmiers.webp', chipBg: '#E4F3EC', chipFg: '#1E7A55' },
    { name: 'Résidence Les Acacias', city: 'Angré, Abidjan', status: 'Ouvriers trouvés', photo: '', chipBg: '#FFF4DA', chipFg: '#9A6A00' },
    { name: 'Duplex Bingerville', city: 'Bingerville', status: 'Brouillon', photo: '', chipBg: '#EEF1EF', chipFg: '#4A5450' },
    { name: 'Villa Riviera', city: 'Riviera 3, Abidjan', status: 'En cours', photo: 'assets/photo-villa.webp', chipBg: '#E4F3EC', chipFg: '#1E7A55' },
  ];
  DEFAULT_NEEDS = { 'Maçon': 2, 'Électricien': 1, 'Plombier': 0, 'Peintre': 0, 'Carreleur': 0, 'Ferrailleur': 1 };
  SHOTS = ['assets/app-besoins.webp', 'assets/app-pointage.webp', '', 'assets/app-roles.webp', 'assets/app-offre.webp', 'assets/app-rapport.webp'];
  chronoRef = React.createRef();

  blankForm() { return { prenom: '', nom: '', tel: '', email: '', naissance: '', genre: '', photo: '', cni: '', metier: '', tarif: '', exp: 5, level: 'Confirmé', sec: [], cert: [], bio: '', commune: '', quartier: '', radius: '10 km', dispo: 'Immédiate', days: ['Lun', 'Mar', 'Mer', 'Jeu', 'Ven'], pay: 'Orange Money', paynum: '', consent: false }; }
  openForm = (target) => {
    this.setState({ formOpen: true, fstep: 0, ferr: '', target: target && target.chantier ? target : null, fv: { ...this.state.fv, metier: target && target.metier ? target.metier : this.state.fv.metier } });
    document.body.style.overflow = 'hidden';
    requestAnimationFrame(() => { const el = this.formRef.current; el && el.animate([{ opacity: 0, transform: 'translateY(30px)' }, { opacity: 1, transform: 'none' }], { duration: 400, easing: 'cubic-bezier(.2,.7,.2,1)' }); });
  };
  closeForm = () => { this.setState({ formOpen: false }); if (this.state.gone || this.state.entered) document.body.style.overflow = ''; };
  componentDidMount() {
    this.onScroll = () => { const v = window.scrollY > 700; if (v !== this.state.showTop) this.setState({ showTop: v }); };
    window.addEventListener('scroll', this.onScroll, { passive: true });
    // N'accepte que les messages venant de la carte intégrée (iframe de la page).
    this.onMsg = (e) => { const d = e.data; if (d && d.type === 'och-apply' && [...document.querySelectorAll('iframe')].some(f => f.contentWindow === e.source)) this.openForm(d); };
    window.addEventListener('message', this.onMsg);
    // Ne re-rend la page que lorsqu'un seuil de mise en page (960 / 1200 px) est franchi.
    const bp = (w) => (w >= 960) + (w >= 1200);
    this.onResize = () => { const w = window.innerWidth; if (bp(w) !== bp(this.state.w)) this.setState({ w }); };
    window.addEventListener('resize', this.onResize);
    this.onWheel = (e) => { if (!this.state.entered && e.deltaY > 25) this.enter(); };
    window.addEventListener('wheel', this.onWheel, { passive: true });
    this.onKey = (e) => { if (!this.state.entered && (e.key === 'Enter' || e.key === 'ArrowDown')) this.enter(); };
    window.addEventListener('keydown', this.onKey);
    this.onTouchStart = (e) => { this.touchY = e.touches[0].clientY; };
    this.onTouchMove = (e) => { if (!this.state.entered && this.touchY - e.touches[0].clientY > 30) this.enter(); };
    window.addEventListener('touchstart', this.onTouchStart, { passive: true });
    window.addEventListener('touchmove', this.onTouchMove, { passive: true });
    this.lockScroll(true);
    this.startCoverAnims();
    if (this.reduced) return;
    this.io = new IntersectionObserver((entries) => entries.forEach(en => {
      if (en.isIntersecting) { en.target.animate([{ opacity: 0, transform: 'translateY(32px)' }, { opacity: 1, transform: 'none' }], { duration: 800, easing: 'cubic-bezier(.2,.7,.2,1)', fill: 'backwards' }); this.io.unobserve(en.target); }
    }), { threshold: .12 });
    document.querySelectorAll('[data-reveal]').forEach(e => this.io.observe(e));
  }
  componentDidUpdate(pp, ps) {
    if (ps.step !== this.state.step) this.onStep();
    if (ps.active !== this.state.active && this.state.active === 2 && !this.reduced) requestAnimationFrame(() => { const el = this.chronoRef.current; if (!el) return; this.cancel('chronoAnims'); el.animate([{ opacity: 0, transform: 'translateY(40px) scale(.94)' }, { opacity: 1, transform: 'none' }], { duration: 600, easing: 'cubic-bezier(.2,.7,.2,1)' }); this.chronoAnims.push(el.animate([{ transform: 'translateY(0)' }, { transform: 'translateY(-10px)' }], { duration: 2200, delay: 600, iterations: Infinity, direction: 'alternate', easing: 'ease-in-out' })); });
  }
  componentWillUnmount() {
    window.removeEventListener('resize', this.onResize); window.removeEventListener('message', this.onMsg); window.removeEventListener('scroll', this.onScroll); window.removeEventListener('wheel', this.onWheel); window.removeEventListener('keydown', this.onKey);
    window.removeEventListener('touchstart', this.onTouchStart); window.removeEventListener('touchmove', this.onTouchMove);
    ['radarAnims', 'chronoAnims', 'coverAnims'].forEach(k => this.cancel(k)); this.io && this.io.disconnect(); clearTimeout(this.t); this.lockScroll(false);
  }
  cancel(k) { this[k].forEach(a => a.cancel()); this[k] = []; }
  lockScroll(on) { document.documentElement.style.overflow = on ? 'hidden' : ''; document.body.style.overflow = on ? 'hidden' : ''; }
  startCoverAnims() {
    this.cancel('coverAnims');
    // Mouvement réduit : pas de vidéo ni d'animations en boucle, le décor reste fixe.
    if (this.reduced) return;
    const run = (el, k, o) => el && this.coverAnims.push(el.animate(k, o));
    const v = this.videoRef.current;
    if (v) {
      v.muted = true; v.defaultMuted = true;
      fetch('assets/chantier.mp4', { method: 'HEAD' }).then(res => {
        if (!res.ok || !this.videoRef.current) return;
        v.addEventListener('playing', () => { v.style.opacity = 1; }, { once: true });
        v.src = 'assets/chantier.mp4'; const p = v.play(); p && p.catch(() => {});
      }).catch(() => {});
    }
    run(this.coverStripeRef.current, [{ backgroundPosition: '0 0' }, { backgroundPosition: '39.6px 0' }], { duration: 900, iterations: Infinity });
    run(this.liveDotRef.current, [{ opacity: 1 }, { opacity: .2 }], { duration: 900, iterations: Infinity, direction: 'alternate' });
    run(this.coverContentRef.current, [{ opacity: 0, transform: 'translateY(40px)' }, { opacity: 1, transform: 'none' }], { duration: 1100, delay: 250, easing: 'cubic-bezier(.2,.7,.2,1)', fill: 'backwards' });
    const root = this.sceneRef.current; if (!root) return;
    run(root, [{ opacity: 0, transform: 'translateY(30px)' }, { opacity: 1, transform: 'none' }], { duration: 1400, easing: 'ease-out', fill: 'backwards' });
    root.querySelectorAll('[data-a]').forEach(el => {
      const a = el.dataset.a;
      if (a === 'floor') {
        const i = +el.dataset.i, s = .06 + i * .1;
        run(el, [{ offset: 0, opacity: 0, transform: 'scaleY(0)' }, { offset: s, opacity: 0, transform: 'scaleY(0)' }, { offset: s + .06, opacity: 1, transform: 'scaleY(1)' }, { offset: .93, opacity: 1, transform: 'scaleY(1)' }, { offset: 1, opacity: 0, transform: 'scaleY(1)' }], { duration: 16000, iterations: Infinity, easing: 'ease-out' });
      } else if (a === 'trolley') {
        run(el, [{ offset: 0, left: '6%' }, { offset: .3, left: '6%' }, { offset: .65, left: '62%' }, { offset: 1, left: '62%' }], { duration: 8000, iterations: Infinity, direction: 'alternate', easing: 'ease-in-out' });
      } else if (a === 'cable') {
        run(el, [{ offset: 0, height: '18%' }, { offset: .15, height: '72%' }, { offset: .3, height: '18%' }, { offset: .65, height: '18%' }, { offset: .82, height: '38%' }, { offset: 1, height: '38%' }], { duration: 8000, iterations: Infinity, direction: 'alternate', easing: 'ease-in-out' });
      } else if (a === 'worker') {
        const d = +el.dataset.dist, dur = +el.dataset.dur;
        run(el, [{ transform: 'translateX(0)' }, { transform: 'translateX(' + d + 'px)' }], { duration: dur, iterations: Infinity, direction: 'alternate', easing: 'linear' });
      } else if (a === 'leg' || a === 'leg2') {
        const s = a === 'leg' ? 1 : -1;
        run(el, [{ transform: 'rotate(' + (18 * s) + 'deg)' }, { transform: 'rotate(' + (-18 * s) + 'deg)' }], { duration: 420, iterations: Infinity, direction: 'alternate', easing: 'ease-in-out' });
      } else if (a === 'blink') {
        run(el, [{ opacity: 1 }, { opacity: .1 }], { duration: 700, iterations: Infinity, direction: 'alternate' });
      }
    });
  }
  enter = () => {
    if (this.state.entered) return;
    window.scrollTo(0, 0); this.setState({ entered: true }); this.lockScroll(false);
    setTimeout(() => { const v = this.videoRef.current; v && v.pause(); this.cancel('coverAnims'); this.setState({ gone: true }); }, 1050);
  };
  replay = () => {
    window.scrollTo(0, 0); this.lockScroll(true);
    this.setState({ gone: false, entered: true }, () => requestAnimationFrame(() => requestAnimationFrame(() => { this.setState({ entered: false }); this.startCoverAnims(); })));
  };
  onStep() {
    const s = this.screenRef.current;
    this.cancel('radarAnims');
    if (this.reduced) return;
    s && s.animate([{ opacity: 0, transform: 'translateX(18px)' }, { opacity: 1, transform: 'none' }], { duration: 320, easing: 'ease-out' });
    requestAnimationFrame(() => {
      if (this.state.step === 3 && s) s.querySelectorAll('[data-radar]').forEach((el, i) => this.radarAnims.push(el.animate([{ transform: 'scale(.3)', opacity: 1 }, { transform: 'scale(1)', opacity: 0 }], { duration: 1500, delay: i * 500, iterations: Infinity, easing: 'ease-out' })));
    });
  }
  needs() { return this.state.needs || this.DEFAULT_NEEDS; }
  total() { return Object.values(this.needs()).reduce((a, b) => a + b, 0); }
  launch = () => {
    if (!this.total()) return;
    clearTimeout(this.t); this.setState({ step: 3 });
    this.t = setTimeout(() => this.setState({ step: 4 }), 1900);
  };

  renderVals() {
    const a = this.state.active, st = this.state.step;
    const fmt = (n) => n.toLocaleString('fr-FR').replace(/\u202f|\u00a0/g, ' ') + ' FCFA';
    const curRaw = this.CHANTIERS[this.state.ch];
    const cur = { ...curRaw, photoDisplay: curRaw.photo ? 'block' : 'none' };
    const needs = this.needs();
    const tot = this.total();
    const perDay = Object.entries(needs).reduce((s, [m, n]) => s + n * this.TARIFS[m], 0);
    const plural = (n, w) => n + ' ' + w + (n > 1 ? 's' : '');
    const setNeed = (m, d) => this.setState(s => { const n = { ...(s.needs || this.DEFAULT_NEEDS) }; n[m] = Math.max(0, Math.min(9, n[m] + d)); return { needs: n }; });
    let k = 0;
    const team = [];
    Object.entries(needs).forEach(([m, n]) => { for (let x = 0; x < n; x++) { team.push({ metier: m, name: this.POOL[m][x % this.POOL[m].length], tarif: fmt(this.TARIFS[m]), photo: this.PHOTOS[k++ % 3] }); } });
    const stepIdx = st <= 1 ? 0 : st === 2 ? 1 : st <= 4 ? 2 : 3;
    const stepsDef = [
      ['1', 'Choisissez le chantier', 'Tous vos chantiers et leur avancement.', () => this.setState({ step: 1 })],
      ['2', 'Indiquez vos besoins par métier', 'Dates, nombre d’ouvriers et tarif moyen.', () => this.setState({ step: 2 })],
      ['3', 'Recevez une offre vérifiée', 'Les ouvriers disponibles près du chantier.', () => this.launch()],
      ['4', 'Payez l’acompte en sécurité', '30 % à la réservation, solde après validation.', () => this.setState({ step: 5 })],
    ];
    const home = st === 0;
    const fv = this.state.fv, fs = this.state.fstep;
    const setF = (k, v) => this.setState(s => ({ fv: { ...s.fv, [k]: v }, ferr: '' }));
    const on = {};
    ['prenom', 'nom', 'tel', 'email', 'naissance', 'genre', 'metier', 'tarif', 'bio', 'commune', 'quartier', 'paynum'].forEach(k => { on[k] = (e) => setF(k, e.target.value); });
    on.exp = (e) => setF('exp', +e.target.value);
    on.photo = (e) => { const f = e.target.files && e.target.files[0]; this.files.photo = f || null; setF('photo', f ? f.name : ''); };
    on.cni = (e) => { const f = e.target.files && e.target.files[0]; this.files.cni = f || null; setF('cni', f ? f.name : ''); };
    on.consent = (e) => setF('consent', e.target.checked);
    const single = (key, items) => items.map(l => ({ label: l, on: fv[key] === l, bg: fv[key] === l ? '#14261F' : '#FFFFFF', fg: fv[key] === l ? '#F8F7F3' : '#14261F', toggle: () => setF(key, l) }));
    const multi = (key, items) => items.map(l => { const onx = fv[key].includes(l); return { label: l, on: onx, bg: onx ? '#14261F' : '#FFFFFF', fg: onx ? '#F8F7F3' : '#14261F', toggle: () => setF(key, onx ? fv[key].filter(x => x !== l) : [...fv[key], l]) }; });
    const validate = () => {
      if (fs === 0 && (!fv.prenom.trim() || !fv.nom.trim() || !fv.tel.trim())) return 'Renseignez votre prénom, votre nom et votre téléphone.';
      if (fs === 0 && !fv.cni) return 'Ajoutez une photo de votre pièce d’identité.';
      if (fs === 1 && !fv.metier) return 'Choisissez votre spécialité principale.';
      if (fs === 2 && !fv.commune) return 'Indiquez votre commune ou ville.';
      if (fs === 2 && !fv.consent) return 'Acceptez les conditions pour envoyer votre profil.';
      return '';
    };
    const cv = this.state.cv;
    const setC = (k, v) => this.setState(s => ({ cv: { ...s.cv, [k]: v }, cErr: '' }));
    const con = {};
    ['nom', 'entreprise', 'tel', 'email', 'objet', 'message'].forEach(k => { con[k] = (e) => setC(k, e.target.value); });
    con.consent = (e) => setC('consent', e.target.checked);
    const cSubmit = (e) => {
      e.preventDefault();
      if (!cv.nom.trim() || !cv.tel.trim() || !cv.email.trim() || !cv.message.trim()) return this.setState({ cErr: 'Renseignez votre nom, téléphone, e-mail et message.' });
      if (!/^\S+@\S+\.\S+$/.test(cv.email)) return this.setState({ cErr: 'L’adresse e-mail semble incorrecte.' });
      if (!cv.consent) return this.setState({ cErr: 'Merci d’accepter l’utilisation de vos informations.' });
      if (this.state.cSending) return;
      this.setState({ cSending: true });
      this.send('/contact', this.toFormData(cv))
        .then(d => this.setState({ cSending: false, cSent: true, cRef: d.reference }))
        .catch(err => this.setState({ cSending: false, cErr: err.message }));
    };
    const stepNames = ['1 · Identité', '2 · Métier & expertise', '3 · Localisation & disponibilité'];
    return {
      cv, con, cSubmit, cSent: this.state.cSent, cNotSent: !this.state.cSent, cErr: this.state.cErr, cHasErr: !!this.state.cErr, cRef: this.state.cRef,
      cBtn: this.state.cSending ? 'Envoi en cours…' : 'Envoyer au Back Office →',
      cProfils: ['Promoteur immobilier', 'Entreprise de BTP', 'Technicien chantier', 'Ouvrier', 'Autre'].map(l => ({ label: l, on: cv.profil === l, bg: cv.profil === l ? '#14261F' : '#FFFFFF', fg: cv.profil === l ? '#F8F7F3' : '#14261F', pick: () => setC('profil', l) })),
      cReset: () => this.setState(s => ({ cSent: false, cv: { ...s.cv, message: '', consent: false } })),
      nlEmail: this.state.nlEmail, nlErr: this.state.nlErr, nlErrShow: !!this.state.nlErr, nlDone: this.state.nlDone, nlNotDone: !this.state.nlDone,
      nlChange: (e) => this.setState({ nlEmail: e.target.value, nlErr: '' }),
      nlSubmit: (e) => { e.preventDefault(); if (!/^\S+@\S+\.\S+$/.test(this.state.nlEmail)) return this.setState({ nlErr: 'Saisissez une adresse e-mail valide.' }); this.send('/newsletter', this.toFormData({ email: this.state.nlEmail })).then(() => this.setState({ nlDone: true })).catch(err => this.setState({ nlErr: err.message })); },
      showTop: !!this.state.showTop && !this.state.formOpen && this.state.gone, toTop: () => window.scrollTo({ top: 0, behavior: 'smooth' }),
      formOpen: this.state.formOpen, openForm: () => this.openForm(null), openFormLink: (e) => { e.preventDefault(); this.openForm(null); }, closeForm: this.closeForm,
      hasTarget: !!this.state.target, targetLabel: this.state.target ? this.state.target.chantier + ' (' + this.state.target.commune + ') · ' + this.state.target.metier : '',
      formNotDone: fs < 3, formDone: fs === 3, f0: fs === 0, f1: fs === 1, f2: fs === 2,
      fSteps: stepNames.map((l, i) => ({ label: l, bar: i <= fs ? '#1E6B4F' : '#D9D6CD', fg: i === fs ? '#14261F' : '#5B645F' })),
      fv, on, ferr: this.state.ferr, hasErr: !!this.state.ferr,
      photoLabel: fv.photo ? '✓ ' + fv.photo : '+ Ajouter une photo',
      cniLabel: fv.cni ? '✓ ' + fv.cni : '+ Ajouter un document',
      expLabel: fv.exp + (fv.exp > 1 ? ' ans' : ' an'),
      levelList: single('level', ['Apprenti', 'Confirmé', 'Expert', 'Chef d’équipe']),
      secList: multi('sec', ['Maçonnerie', 'Coffrage', 'Ferraillage', 'Électricité', 'Plomberie', 'Carrelage', 'Peinture', 'Menuiserie', 'Soudure', 'Étanchéité']),
      certList: multi('cert', ['CAP / BT', 'BTS / Licence', 'Travail en hauteur', 'Habilitation électrique', 'Conduite d’engins', 'Secourisme (SST)']),
      radiusList: single('radius', ['5 km', '10 km', '25 km', '50 km', 'Toute la Côte d’Ivoire']),
      dispoList: single('dispo', ['Immédiate', 'Sous 1 semaine', 'Sous 1 mois']),
      daysList: multi('days', ['Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam', 'Dim']),
      payList: single('pay', ['Orange Money', 'MTN MoMo', 'Moov Money', 'Wave', 'Virement']),
      prevVis: fs > 0 ? 'visible' : 'hidden', nextLabel: fs === 2 ? (this.state.fSending ? 'Envoi en cours…' : 'Envoyer mon profil') : 'Continuer →',
      fPrev: () => this.setState({ fstep: Math.max(0, fs - 1), ferr: '' }),
      fNext: () => {
        const err = validate(); if (err) return this.setState({ ferr: err });
        if (fs < 2) return this.setState({ fstep: fs + 1, ferr: '' });
        if (this.state.fSending) return;
        const t = this.state.target;
        const fd = this.toFormData({ ...fv, photo: null, cni: null, chantier: t ? t.chantier : null, chantier_commune: t ? t.commune : null });
        if (this.files.photo) fd.append('photo', this.files.photo);
        if (this.files.cni) fd.append('cni', this.files.cni);
        this.setState({ fSending: true });
        this.send('/ouvriers', fd)
          .then(() => { this.files = { photo: null, cni: null }; this.setState({ fSending: false, fstep: 3, ferr: '' }); })
          .catch(e => this.setState({ fSending: false, ferr: e.message }));
      },
      summary: [fv.metier, fv.level, fv.exp + ' ans d’expérience', fv.commune + (fv.quartier ? ' · ' + fv.quartier : ''), 'Disponibilité : ' + fv.dispo, 'Paiement : ' + fv.pay].filter(Boolean).join(' · '),
      showCover: !this.state.gone,
      coverTransform: this.state.entered ? 'translateY(-100%)' : 'translateY(0)',
      enter: this.enter, replay: this.replay,
      logoClick: (e) => { e.preventDefault(); this.setState({ menuOpen: false }); this.replay(); },
      videoRef: this.videoRef, coverStripeRef: this.coverStripeRef, liveDotRef: this.liveDotRef,
      coverContentRef: this.coverContentRef, sceneRef: this.sceneRef, screenRef: this.screenRef,
      sceneOpacity: this.state.w < 1200 ? 0.3 : 1,
      isDesktop: this.state.w >= 960, isMobile: this.state.w < 960,
      menuOpen: this.state.menuOpen && this.state.w < 960, menuIcon: this.state.menuOpen ? '✕' : '☰',
      toggleMenu: () => this.setState(s => ({ menuOpen: !s.menuOpen })), closeMenu: () => this.setState({ menuOpen: false }),
      demoSteps: stepsDef.map((d, i) => ({ n: d[0], title: d[1], text: d[2], go: d[3], bg: i === stepIdx && !home ? '#14261F' : 'transparent', fg: i === stepIdx && !home ? '#F8F7F3' : '#14261F', dot: i === stepIdx && !home ? '#FAB82A' : (i < stepIdx ? '#3EB884' : '#E8E6DF') })),
      s0: st === 0, s1: st === 1, s2: st === 2, s3: st === 3, s4: st === 4, s5: st === 5,
      startResa: () => this.setState({ step: 1 }),
      chantiers: this.CHANTIERS.map((c, i) => ({ ...c, photoDisplay: c.photo ? 'block' : 'none', border: i === this.state.ch ? '#0E4A3A' : '#EEF1EF', open: () => this.setState({ ch: i, step: 2 }) })),
      cur, change: () => this.setState({ step: 1 }),
      needsList: Object.entries(needs).map(([m, n]) => ({ metier: m, count: n, tarif: fmt(this.TARIFS[m]), plural: this.PLURAL[m], inc: () => setNeed(m, 1), dec: () => setNeed(m, -1) })),
      totalLabel: plural(tot, 'ouvrier'),
      estimate: fmt(perDay * 5), perDay: fmt(perDay), deposit: fmt(Math.round(perDay * 5 * 0.3)), balance: fmt(perDay * 5 - Math.round(perDay * 5 * 0.3)),
      launchBg: tot ? '#F2B21E' : '#E8ECE9',
      launch: this.launch,
      back: () => this.setState({ step: st === 4 ? 2 : Math.max(0, st - 1) }),
      team, confirm: () => this.setState({ step: 5 }),
      restart: () => this.setState({ step: 0, needs: null, ch: 0 }),
      navHomeW: home ? 800 : 500, navHomeC: home ? '#0E4A3A' : '#6B7570', navHomeBg: home ? '#D7EEE3' : 'transparent',
      navResaW: home ? 500 : 800, navResaC: home ? '#6B7570' : '#0E4A3A', navResaBg: home ? 'transparent' : '#D7EEE3',
      hasShot: !!this.SHOTS[a], isChrono: a === 2, chronoRef: this.chronoRef, activeShot: this.SHOTS[a],
      features: this.FEATS.map((f, i) => ({ ...f, isCore: !!f.isCore, select: () => this.setState({ active: i }), bg: i === a ? '#14261F' : 'transparent', fg: i === a ? '#F8F7F3' : '#14261F', num: i === a ? '#FAB82A' : '#1E6B4F' })),
    };
  }
}
</script>
</body>
</html>
