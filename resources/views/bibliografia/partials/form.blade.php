<form method="POST" action="{{ route('bibliografia.bibliografia') }}">
  @csrf
  <div class="form-group">
    <label for="message">
      Referências bibliográficas
      <span class="badge badge-outline-info usptheme-contador-placeholder">
        <span class="usptheme-contador-paragrafos"></span>
        · <span class="usptheme-contador-palavras"></span>
        · <span class="usptheme-contador-caracteres"></span>
      </span>
    </label>
    {{-- Recupera o campo enviado no POST ao voltar após validação ou falha da IA. --}}
    <textarea id="message" name="message" class="form-control usptheme-contador" rows="6" maxlength="10000"
      placeholder="Cole aqui as referências bibliográficas..." required>{{ old('message', $referencias ?? '') }}</textarea>
  </div>
  <div class="d-flex align-items-end mb-2">
    <div class="form-group mb-0 mr-2">
      <label for="standards" class="font-weight-bold text-secondary mb-1">
        Padrão de Avaliação
      </label>
      <select name="standard" id="standards" class="custom-select custom-select-sm">
        @foreach ($standards as $s)
          <option value="{{ $s }}"
            {{ old('standard', $standard ?? '') === $s ? 'selected' : '' }}>
            {{ $s }}
          </option>
        @endforeach
      </select>
    </div>
    <button type="submit" class="btn btn-primary btn-sm btn-spinner">Processar</button>
  </div>
</form>
