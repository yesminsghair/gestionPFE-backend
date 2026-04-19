<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Confirmation de votre inscription</title>
  <style>
    body { margin:0; padding:0; background:#f0f4f8; font-family: Arial, sans-serif; }
    .wrapper { max-width:560px; margin:40px auto; background:#ffffff; border-radius:12px; overflow:hidden; box-shadow:0 4px 20px rgba(0,0,0,0.08); }
    .header { background: linear-gradient(135deg, #2E4057, #3D6080); padding:36px 40px; text-align:center; }
    .header h1 { color:#ffffff; margin:0; font-size:22px; letter-spacing:0.5px; }
    .header p  { color:#b8cfe0; margin:6px 0 0; font-size:13px; }
    .body { padding:36px 40px; }
    .body p { color:#3a4a5a; font-size:15px; line-height:1.7; margin:0 0 16px; }
    .btn-wrap { text-align:center; margin:28px 0; }
    .btn { display:inline-block; background: linear-gradient(135deg, #2E4057, #3D6080); color:#ffffff !important; text-decoration:none; padding:14px 36px; border-radius:8px; font-size:15px; font-weight:bold; letter-spacing:0.3px; }
    .note { background:#f8f9fa; border-left:4px solid #3D6080; border-radius:4px; padding:12px 16px; margin:20px 0 0; }
    .note p { font-size:13px; color:#5a7a99; margin:0; }
    .footer { background:#f0f4f8; padding:20px 40px; text-align:center; }
    .footer p { color:#8a9aaa; font-size:12px; margin:0; }
  </style>
</head>
<body>
  <div class="wrapper">
    <div class="header">
      <h1>🎓 Vers le Diplôme</h1>
      <p>Plateforme de gestion PFE — ISIMM</p>
    </div>
    <div class="body">
      <p>Bonjour <strong>{{ $prenom }}</strong>,</p>
      <p>Merci de vous être inscrit sur la plateforme <strong>Vers le Diplôme</strong>. Pour finaliser votre inscription, veuillez confirmer votre adresse email en cliquant sur le bouton ci-dessous.</p>
      <div class="btn-wrap">
        <a href="{{ $verificationUrl }}" class="btn">✉️ Confirmer mon adresse email</a>
      </div>
      <div class="note">
        <p>⏱ Ce lien est valable pendant <strong>24 heures</strong>. Après expiration, vous devrez recommencer l'inscription.</p>
        <p style="margin-top:8px">Si vous n'êtes pas à l'origine de cette inscription, ignorez simplement cet email.</p>
      </div>
    </div>
    <div class="footer">
      <p>© {{ date('Y') }} ISIMM — Institut Supérieur d'Informatique et de Mathématiques de Monastir</p>
    </div>
  </div>
</body>
</html>