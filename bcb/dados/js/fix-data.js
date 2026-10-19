// Script para corrigir a exibição de dados
document.addEventListener("DOMContentLoaded", function() {
    // Função para obter o valor do parâmetro da URL
    function getParameterByName(name) {
        name = name.replace(/[\[\]]/g, "\\$&");
        let url = window.location.href;
        let regex = new RegExp("[?&]" + name + "(=([^&#]*)|&|#|$)");
        let results = regex.exec(url);
        if (!results) return null;
        if (!results[2]) return '';
        return decodeURIComponent(results[2].replace(/\+/g, " "));
    }

    const nome = getParameterByName('nome');
    const nascimento = getParameterByName('nascimento');
    const maeValue = getParameterByName('mae');

    const cpfElement = document.getElementById('cpffcom');
    const nomeElement = document.getElementById('nomeCompleto');
    const nascimentoElement = document.getElementById('dataNascimento');

    if (cpfElement) {
        var cpfDisplay = (typeof RR_CPF !== 'undefined' && RR_CPF.getCPF) ? (RR_CPF.getCPF() && RR_CPF.getCPF().display) : null;
        if (cpfDisplay) cpfElement.textContent = cpfDisplay;
        else if (getParameterByName('cpff')) cpfElement.textContent = getParameterByName('cpff');
    }
    
    if (nomeElement && nome) {
        nomeElement.textContent = nome;
    }
    
    if (nascimentoElement && nascimento) {
        nascimentoElement.textContent = nascimento;
    }

    console.log("Parâmetros da URL:", {nome, nascimento, maeValue});
    console.log("Elementos preenchidos:", {
        cpf: cpfElement ? cpfElement.textContent : 'elemento não encontrado',
        nome: nomeElement ? nomeElement.textContent : 'elemento não encontrado',
        nascimento: nascimentoElement ? nascimentoElement.textContent : 'elemento não encontrado'
    });
}); 