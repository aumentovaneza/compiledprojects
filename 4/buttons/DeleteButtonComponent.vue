<!-- The delete button was made into component since it was one of the repeating
functionalities for this app.  -->

<template>
<b-button type="submit" :disabled="!disabled" variant="danger" @click.preventDefault="deleteRecord" >Delete</b-button>
</template>
<script>
  export default {
       name: 'DeleteButtonComponent',
       props: [
         'disabled','name'
       ],
       methods: {
         deleteRecord: function() {
             this.$bvModal.msgBoxConfirm('Please confirm that you want to delete this '+this.name+".", {
               title: 'Please Confirm',
               size: 'sm',
               buttonSize: 'sm',
               okVariant: 'danger',
               okTitle: 'YES',
               cancelTitle: 'NO',
               footerClass: 'p-2',
               hideHeaderClose: false,
               centered: true
             }).then(value => {
                 if (value === true) {
                   this.$emit('deleteConfirmed')
                 } else {
                   this.$emit('deleteDeclined')
                 }
             }).catch(err => {

             })
           },
       },
   };
</script>
<style scoped>
  .btn.disabled {
    cursor: auto;
  }
</style>
