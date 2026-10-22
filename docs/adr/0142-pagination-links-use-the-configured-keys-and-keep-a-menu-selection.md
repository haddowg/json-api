# Pagination links use the configured keys and keep a menu selection

Page value objects built their links from hardcoded `number`/`size`/`offset`/`limit` keys, so a
paginator with renamed keys linked to parameters it doesn't read. A menu's links also lost the
strategy the request chose, because the new `page` object replaces the request's whole `page` array,
so a link made only of shared keys fell back to the menu default. Pages now take their paginator's
configured keys. A strategy a `MultiPaginator` picks over its default is handed back through
`SelectableFromMenuInterface::selectedFromMenu()` and puts `page[kind]` into every link it renders;
the default strategy's links are unchanged, so existing responses keep their wire form.
