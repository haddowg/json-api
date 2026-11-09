# An action's document input is its type's create request

A custom action taking a JSON:API document parses, validates and hydrates it in a create context, so its request body `$ref`s the input type's `<Type>CreateRequest`, and that component and `<Type>CreateAttributes` are emitted for every such input type even when the type itself does not expose create. We kept the existing create components rather than inventing a parallel `<Type>ActionInput` set, so a type that can be created and also feeds an action is described once, and generated clients keep the names they already use.
