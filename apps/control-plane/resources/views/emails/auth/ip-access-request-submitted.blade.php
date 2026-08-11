<!DOCTYPE html>
<html>
<body>
    <p>O e-mail <strong>{{ $ipAccessRequest->requested_email }}</strong> solicitou liberação de
    acesso ao apy-gateway a partir do IP <strong>{{ $ipAccessRequest->ip_address }}</strong>.</p>

    <p><a href="{{ $approveUrl }}">Aprovar acesso</a></p>

    <p>Este link expira em breve e só pode ser usado uma vez. Se você não reconhece esta
    solicitação, ignore este e-mail.</p>
</body>
</html>
