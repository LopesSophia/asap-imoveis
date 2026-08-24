Extraia do texto transcrito abaixo os dados de endereço do imóvel.
Responda SOMENTE em JSON, sem texto adicional, sem markdown.

Formato de saída:
{
  "logradouro": "string ou null",
  "numero": "string ou null",
  "bairro": "string ou null",
  "cidade": "string ou null",
  "cep": "string ou null",
  "complemento": "string ou null",
  "confianca": "alta | media | baixa"
}

Regras:
- Nunca invente dados ausentes na fala. Se não houver menção, retorne null.
- "confianca" deve refletir o quanto o texto fornece endereço completo e sem ambiguidade.
- Cidade default é "São Paulo" apenas se o contexto indicar claramente Zona Leste/SP e não houver menção de outra cidade.
