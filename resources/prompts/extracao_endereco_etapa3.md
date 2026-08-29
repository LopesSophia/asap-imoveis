Extraia do texto transcrito abaixo os dados de endereço do imóvel.
Responda SOMENTE em JSON, sem texto adicional, sem markdown.

Formato de saída:
{
  "logradouro": "string ou null",
  "numero": "string ou null",
  "bairro": "string ou null",
  "cidade": "string ou null",
  "estado": "sigla de 2 letras (UF), ex. \"SP\", ou null",
  "cep": "string ou null",
  "complemento": "string ou null",
  "confianca": "alta | media | baixa"
}

Regras:
- Nunca invente dados ausentes na fala. Se não houver menção, retorne null.
- Se cidade e/ou estado forem mencionados explicitamente no texto — mesmo
  que abreviados (ex.: "SP") ou junto com outras informações (ex.: "Vila
  Mariana, São Paulo - SP") — extraia exatamente o que foi dito. Menção
  explícita NUNCA vira null, mesmo que o bairro citado não seja
  reconhecível ou não se encaixe em nenhuma regra de default abaixo.
- "estado" é sempre a sigla de 2 letras (UF, maiúsculas), nunca o nome
  completo do estado (ex.: "SP", nunca "São Paulo").
- Cidade/estado default são "São Paulo"/"SP" SOMENTE quando NENHUMA
  cidade for mencionada no texto e o bairro citado for claramente
  reconhecível como um bairro da Zona Leste de São Paulo. Fora desse
  cenário específico, sem menção explícita, retorne null — nunca infira.
- "confianca" deve refletir o quanto o texto fornece endereço completo e sem ambiguidade.
