<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Réinitialisation de mot de passe</title>
  <style>
    body { margin:0; padding:0; background:#f0f4f8; font-family: Arial, sans-serif; }
    .wrapper { max-width:560px; margin:40px auto; background:#ffffff; border-radius:12px; overflow:hidden; box-shadow:0 4px 20px rgba(0,0,0,0.08); }
    .header { background: linear-gradient(135deg, #7B3F00, #B8621A); padding:36px 40px; text-align:center; }
    .header h1 { color:#ffffff; margin:0; font-size:22px; letter-spacing:0.5px; }
    .header p  { color:#f0d0b0; margin:6px 0 0; font-size:13px; }
    .body { padding:36px 40px; }
    .body p { color:#3a4a5a; font-size:15px; line-height:1.7; margin:0 0 16px; }
    .btn-wrap { text-align:center; margin:28px 0; }
    .btn { display:inline-block; background: linear-gradient(135deg, #7B3F00, #B8621A); color:#ffffff !important; text-decoration:none; padding:14px 36px; border-radius:8px; font-size:15px; font-weight:bold; }
    .note { background:#fff8f0; border-left:4px solid #B8621A; border-radius:4px; padding:12px 16px; margin:20px 0 0; }
    .note p { font-size:13px; color:#8a5a3a; margin:0; }
    .footer { background:#f0f4f8; padding:20px 40px; text-align:center; }
    .footer p { color:#8a9aaa; font-size:12px; margin:0; }
  </style>
</head>
<body>
  <div class="wrapper">
    <div class="header">
      <h1>🔐 Réinitialisation de mot de passe</h1>
      <p>Vers le Diplôme — ISIMM</p>
    </div>
    <div class="body">
      <p>Bonjour <strong>{{ $prenom }}</strong>,</p>
      <p>Nous avons reçu une demande de réinitialisation de mot de passe pour votre compte. Cliquez sur le bouton ci-dessous pour choisir un nouveau mot de passe.</p>
      <div class="btn-wrap">
        <a href="{{ $resetUrl }}" class="btn">🔑 Réinitialiser mon mot de passe</a>
      </div>
      <div class="note">
        <p>⏱ Ce lien expire dans <strong>60 minutes</strong>.</p>
        <p style="margin-top:8px">Si vous n'avez pas demandé cette réinitialisation, ignorez cet email — votre mot de passe ne sera pas modifié.</p>
      </div>
    </div>
    <div class="footer">
      <p>© {{ date('Y') }} ISIMM — Institut Supérieur d'Informatique et de Mathématiques de Monastir</p>
    </div>
  </div>
</body>
</html>