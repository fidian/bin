" Set up ALE
let b:ale_linters = ['tsserver']
let b:ale_fixers = ['prettier', 'eslint']

" Ctrl-? for a type hint
nnoremap <silent> <C-?> :ALEHover<CR>
