---
Proyecto: Base de datos en txt para calendario
Experimentalidad: ALTA
Utilidad: 0
---

# Introducción

Preciso almacenar eventos de un calendario y como no quiero usar una base de datos vamos a generar un BST en un archivo para almacenar los EPOCH de un día de calendario.

Donde los valores de cada nodo sean clave `unit32` valor `char[N]`, siendo `N` un tamaño fijo. Para que se sienta un poquito dinámico al primer `\0`.

Desconozco cuanto se va a caer a pedazos por el random IO pero seguro que va a ser divertido.

# ¿Cómo vamos a encodear?

TODO: Revisar bien el formato porque me estoy mancando seguro.

1. Puntero byte del archivo donde reside el nodo inquiero
2. Valor de la clave
3. Puntero byte del archivo donde reside el nodo derecho
4. String horrible con el valor

# Hitos

- [x] Implementar el tooling BT en un archivo (tener archivos funcionales , obtener nodo, etc.)
- [x] Operaciones comunes BT (insertar, buscar, obtener rango y para no complicar con el borrado únicamente borrar el detalle (cuenta como update))
- [x] Hacer API simplona para abstraer el TAD
- [X] Digievolución de BT a BST
- [ ] Hacer una clase de calendario que maneje el CRUD
- [ ] Endpoints CRUD
- [ ] Integrar con horrible HTML
- [x] Considerar si se cae a pedazos implementar una leve capa de cache: (clave, offset, izq, der)
